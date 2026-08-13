<?php

namespace App\Services\Clustering;

use App\Data\Clustering\ArticleCluster;

final class BriefingSelector
{
    public function __construct(private readonly BriefingScorer $scorer = new BriefingScorer) {}

    /** @param list<ArticleCluster> $clusters @return list<ArticleCluster> */
    public function select(array $clusters, int $topN = 7): array
    {
        if ($topN < 1) {
            throw new \InvalidArgumentException('topN must be at least 1.');
        }

        $scored = array_map(fn (ArticleCluster $cluster) => $this->scorer->score($cluster), $clusters);
        usort($scored, static fn ($left, $right): int => $right->score <=> $left->score
            ?: $right->cluster->latestPublishedAt <=> $left->cluster->latestPublishedAt
            ?: strcmp($left->cluster->representativeTitle, $right->cluster->representativeTitle)
            ?: strcmp($left->cluster->members[0]->id, $right->cluster->members[0]->id));

        return array_map(static fn ($score): ArticleCluster => $score->cluster, array_slice($scored, 0, $topN));
    }
}
