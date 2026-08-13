<?php

namespace App\Services\Clustering;

use App\Data\Clustering\ArticleCluster;
use App\Data\Clustering\ClusterableArticleData;
use App\Data\Clustering\ClusteringOptions;

final class ArticleClusterer
{
    public function __construct(
        private readonly TitleNormalizer $titleNormalizer = new TitleNormalizer,
        private readonly EntityNormalizer $entityNormalizer = new EntityNormalizer,
    ) {}

    /**
     * Builds deterministic components from pair edges. Edges are ordered by shared
     * entity count, Jaccard strength, and ids; a union is accepted only when the
     * resulting component's earliest/latest span fits the global time window.
     *
     * @param  list<ClusterableArticleData>  $articles
     * @return list<ArticleCluster>
     */
    public function cluster(array $articles, ?ClusteringOptions $options = null): array
    {
        $options ??= new ClusteringOptions;
        if (count($articles) > $options->maxArticles) {
            throw new \InvalidArgumentException('Article count exceeds maxArticles.');
        }
        usort($articles, static fn (ClusterableArticleData $left, ClusterableArticleData $right): int => strcmp($left->id, $right->id));
        $ids = array_map(static fn (ClusterableArticleData $article): string => $article->id, $articles);

        if (count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException('Article ids must be unique.');
        }

        $parents = array_keys($articles);
        $windowSeconds = $options->windowHours * 3600;
        $find = static function (int $index) use (&$parents, &$find): int {
            if ($parents[$index] !== $index) {
                $parents[$index] = $find($parents[$index]);
            }

            return $parents[$index];
        };
        $spans = array_map(static fn (ClusterableArticleData $article): array => [$article->publishedAt->getTimestamp(), $article->publishedAt->getTimestamp()], $articles);
        $union = static function (int $left, int $right) use (&$parents, &$spans, $find, $windowSeconds): bool {
            $left = $find($left);
            $right = $find($right);
            if ($left === $right || max($spans[$left][1], $spans[$right][1]) - min($spans[$left][0], $spans[$right][0]) > $windowSeconds) {
                return false;
            }
            $parents[$right] = $left;
            $spans[$left] = [min($spans[$left][0], $spans[$right][0]), max($spans[$left][1], $spans[$right][1])];

            return true;
        };

        $tokens = array_map(fn (ClusterableArticleData $article): array => $this->titleNormalizer->tokens($article->title), $articles);
        $entities = array_map(fn (ClusterableArticleData $article): array => $this->entityNormalizer->canonicalize($article->entities), $articles);
        $edges = [];

        for ($left = 0; $left < count($articles); $left++) {
            for ($right = $left + 1; $right < count($articles); $right++) {
                if ($articles[$left]->category !== $articles[$right]->category
                    || abs($articles[$left]->publishedAt->getTimestamp() - $articles[$right]->publishedAt->getTimestamp()) > $windowSeconds) {
                    continue;
                }

                $leftTokens = array_values(array_unique($tokens[$left]));
                $rightTokens = array_values(array_unique($tokens[$right]));
                $tokenUnion = array_values(array_unique([...$leftTokens, ...$rightTokens]));
                $tokenIntersection = array_intersect($leftTokens, $rightTokens);
                $jaccard = count($tokenUnion) === 0 ? 0.0 : count($tokenIntersection) / count($tokenUnion);
                // Coincidencia laxa, no array_intersect: dos medios escriben
                // "larrain" y "pedro pablo larrain" para la misma persona, y la
                // igualdad exacta dejaba el mismo hecho como dos acontecimientos.
                $sharedEntities = $this->entityNormalizer->sharedEntities($entities[$left], $entities[$right]);

                if ($jaccard >= $options->tokenJaccardThreshold || count($sharedEntities) >= $options->minimumSharedEntities) {
                    $edges[] = [$left, $right, $jaccard, count($sharedEntities)];
                }
            }
        }

        // Stronger relationships win first; all ties use stable article ids.
        usort($edges, static fn (array $left, array $right): int => $right[3] <=> $left[3] ?: $right[2] <=> $left[2] ?: $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
        foreach ($edges as [$left, $right]) {
            $union($left, $right);
        }

        $components = [];
        foreach ($articles as $index => $article) {
            $components[$find($index)][] = $article;
        }

        $clusters = array_map(function (array $members): ArticleCluster {
            usort($members, static fn (ClusterableArticleData $left, ClusterableArticleData $right): int => strcmp($left->id, $right->id));
            ['canonical' => $canonicalEntities, 'shared' => $sharedEntities] = $this->groupMemberEntities($members);
            $representative = $members[0];
            foreach (array_slice($members, 1) as $member) {
                if ($member->relevance->weight() > $representative->relevance->weight()
                    || ($member->relevance->weight() === $representative->relevance->weight() && $member->publishedAt > $representative->publishedAt)
                    || ($member->relevance === $representative->relevance && $member->publishedAt == $representative->publishedAt && strcmp($member->id, $representative->id) < 0)) {
                    $representative = $member;
                }
            }
            $latest = max(array_map(static fn (ClusterableArticleData $member): \DateTimeImmutable => $member->publishedAt, $members));
            $maxRelevance = array_reduce($members, static fn ($max, ClusterableArticleData $member) => $max === null || $member->relevance->weight() > $max->weight() ? $member->relevance : $max);

            return new ArticleCluster($members, $members[0]->category, $representative->title, count(array_unique(array_map(static fn (ClusterableArticleData $member): string => $member->sourceKey, $members))), $maxRelevance, $latest, $canonicalEntities, $sharedEntities, $representative->id);
        }, array_values($components));
        usort($clusters, static fn (ArticleCluster $left, ArticleCluster $right): int => strcmp($left->members[0]->id, $right->members[0]->id));

        return $clusters;
    }

    /**
     * Agrupa las menciones de los miembros colapsando las variantes del mismo
     * nombre, y devuelve la unión y las que aparecen en 2+ artículos.
     *
     * Va por `matches()` y no por igualdad de clave: si "larrain" y "pedro pablo
     * larrain" contaran por separado, el cluster quedaría con dos entidades de
     * un artículo cada una y `shared` saldría vacío aunque los artículos ya
     * estén agrupados. Se cuenta por artículo distinto, así que dos variantes
     * dentro de la misma nota no inflan el conteo.
     *
     * @param  list<ClusterableArticleData>  $members
     * @return array{canonical: list<string>, shared: list<string>}
     */
    private function groupMemberEntities(array $members): array
    {
        /** @var list<array{name: string, members: array<int, true>}> $groups */
        $groups = [];

        foreach ($members as $memberIndex => $member) {
            foreach ($this->entityNormalizer->canonicalize($member->entities) as $entity) {
                $matched = null;

                foreach ($groups as $groupIndex => $group) {
                    if ($this->entityNormalizer->matches($group['name'], $entity)) {
                        $matched = $groupIndex;
                        break;
                    }
                }

                if ($matched === null) {
                    $groups[] = ['name' => $entity, 'members' => [$memberIndex => true]];

                    continue;
                }

                $groups[$matched]['name'] = $this->entityNormalizer->mostSpecific($groups[$matched]['name'], $entity);
                $groups[$matched]['members'][$memberIndex] = true;
            }
        }

        $canonical = array_map(static fn (array $group): string => $group['name'], $groups);
        sort($canonical);

        $shared = array_map(
            static fn (array $group): string => $group['name'],
            array_filter($groups, static fn (array $group): bool => count($group['members']) >= 2),
        );
        $shared = array_values($shared);
        sort($shared);

        return ['canonical' => $canonical, 'shared' => $shared];
    }
}
