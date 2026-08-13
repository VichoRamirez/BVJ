<?php

use App\Data\Clustering\ClusterableArticleData;
use App\Data\Clustering\ClusteringOptions;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Services\Clustering\ArticleClusterer;
use App\Services\Clustering\BriefingScorer;
use App\Services\Clustering\BriefingSelector;
use App\Services\Clustering\EntityNormalizer;
use App\Services\Clustering\TitleNormalizer;

function article(string $id, string $title, string $source = 'wire', int $hours = 0, RelevanceLevel $relevance = RelevanceLevel::Medium, NewsCategory $category = NewsCategory::Economy, array $entities = []): ClusterableArticleData
{
    return new ClusterableArticleData($id, $title, (new DateTimeImmutable('2026-01-01T00:00:00+00:00'))->modify("+{$hours} hours"), $category, $relevance, $entities, $source);
}

test('normalizes accented, cased and punctuated titles without requiring intl', function () {
    expect(new TitleNormalizer()->tokens('¡ÁCIDO: Banco Central! Straße 東京'))->toBe(['acido', 'banco', 'central', 'straße', '東京'])
        ->and(new TitleNormalizer()->tokens("Cafe\u{301} Straße 東京"))->toBe(['cafe', 'straße', '東京']);
});

test('normalizes entities conservatively', function () {
    $normalizer = new EntityNormalizer;
    expect($normalizer->canonical('Banco Central, S.A.'))->toBe('banco central')
        ->and($normalizer->canonical('Pérez'))->toBe('perez');
});

test('clusters by title threshold, shared entity, category and window', function () {
    $clusterer = new ArticleClusterer;
    $articles = [
        article('a', 'Banco central sube tasas', hours: 0),
        article('b', 'Banco central mantiene tasas', hours: 1),
        article('c', 'Mercado petrolero cambia', hours: 2, entities: ['OPEP']),
        article('d', 'Petróleo y oferta global', hours: 2, entities: ['OPEP']),
        article('e', 'Banco central sube tasas', hours: 26),
        article('f', 'Banco central sube tasas', hours: 1, category: NewsCategory::Markets),
    ];

    $clusters = $clusterer->cluster($articles, new ClusteringOptions(tokenJaccardThreshold: 0.6));
    expect(array_map(fn ($cluster) => array_map(fn ($member) => $member->id, $cluster->members), $clusters))
        ->toBe([['a', 'b'], ['c', 'd'], ['e'], ['f']]);
});

test('uses unique token sets and does not transitively exceed the global window', function () {
    $clusterer = new ArticleClusterer;
    $repeated = $clusterer->cluster([
        article('a', 'banco banco banco tasa'),
        article('b', 'banco tasa mercado'),
    ], new ClusteringOptions(tokenJaccardThreshold: 0.66));
    $spanning = $clusterer->cluster([
        article('a', 'banco tasa', hours: 0),
        article('b', 'banco tasa', hours: 20),
        article('c', 'banco tasa', hours: 40),
    ]);

    expect($repeated)->toHaveCount(1)
        ->and(array_map(fn ($cluster) => count($cluster->members), $spanning))->toBe([2, 1]);
});

test('uses union find transitively and is permutation invariant', function () {
    $articles = [article('a', 'Banco tasa inflación', hours: 0), article('b', 'Banco tasa mercado', hours: 1), article('c', 'Banco mercado acciones', hours: 2)];
    $clusterer = new ArticleClusterer;
    $first = $clusterer->cluster($articles, new ClusteringOptions(tokenJaccardThreshold: 0.5));
    $second = $clusterer->cluster(array_reverse($articles), new ClusteringOptions(tokenJaccardThreshold: 0.5));

    expect($first)->toEqual($second)->and($first[0]->members)->toHaveCount(3);
});

test('builds deterministic aggregate fields and scores sources and relevance', function () {
    $cluster = (new ArticleClusterer)->cluster([
        article('b', 'Título viejo', source: 'one', hours: 0, relevance: RelevanceLevel::Critical, entities: ['Banco S.A.']),
        article('a', 'Título reciente', source: 'two', hours: 1, relevance: RelevanceLevel::High, entities: ['banco']),
        article('c', 'Título igual', source: 'two', hours: 2, relevance: RelevanceLevel::Low),
    ])[0];

    expect($cluster->representativeTitle)->toBe('Título viejo')
        ->and($cluster->distinctSourceCount)->toBe(2)
        ->and($cluster->maxRelevance)->toBe(RelevanceLevel::Critical)
        ->and($cluster->sharedEntities)->toBe(['banco'])
        ->and((new BriefingScorer)->score($cluster)->score)->toBe(5);
});

test('selects top seven or configured count in deterministic order', function () {
    $clusters = [];
    for ($index = 0; $index < 9; $index++) {
        $clusters[] = (new ArticleClusterer)->cluster([article((string) $index, "Título {$index}", hours: $index, relevance: RelevanceLevel::Critical)])[0];
    }

    expect((new BriefingSelector)->select($clusters))->toHaveCount(7)
        ->and((new BriefingSelector)->select($clusters, 2))->toHaveCount(2);
});

test('caps source bonus and applies the complete selector tie-break', function () {
    $cluster = (new ArticleClusterer)->cluster([
        article('a', 'Misma noticia', source: 'one', relevance: RelevanceLevel::Critical),
        article('b', 'Misma noticia', source: 'two', relevance: RelevanceLevel::Critical),
        article('c', 'Misma noticia', source: 'three', relevance: RelevanceLevel::Critical),
        article('d', 'Misma noticia', source: 'four', relevance: RelevanceLevel::Critical),
        article('e', 'Misma noticia', source: 'five', relevance: RelevanceLevel::Critical),
    ])[0];
    $sameDate = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    $first = new ClusterableArticleData('z', 'Igual', $sameDate, NewsCategory::Markets, RelevanceLevel::High, [], 'one');
    $second = new ClusterableArticleData('a', 'Igual', $sameDate, NewsCategory::Companies, RelevanceLevel::High, [], 'one');
    $tieClusters = [(new ArticleClusterer)->cluster([$first])[0], (new ArticleClusterer)->cluster([$second])[0]];

    expect((new BriefingScorer(sourceBonus: 2, maxSourceBonus: 2))->score($cluster)->score)->toBe(8)
        ->and((new BriefingSelector)->select($tieClusters)[0]->members[0]->id)->toBe('a');
});

test('returns empty and rejects invalid DTOs and options', function () {
    expect((new ArticleClusterer)->cluster([]))->toBe([])
        ->and(fn () => new ClusterableArticleData('', 'x', new DateTimeImmutable, NewsCategory::Economy, RelevanceLevel::Low, [], 's'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClusterableArticleData('x', ' ', new DateTimeImmutable, NewsCategory::Economy, RelevanceLevel::Low, [], 's'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClusterableArticleData('x', 'title', new DateTimeImmutable, NewsCategory::Economy, RelevanceLevel::Low, [' '], 's'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClusterableArticleData('x', 'title', new DateTimeImmutable, NewsCategory::Economy, RelevanceLevel::Low, [], ' '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ArticleClusterer)->cluster([article('x', 'title'), article('x', 'other')]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClusteringOptions(windowHours: 169))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClusteringOptions(tokenJaccardThreshold: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClusteringOptions(tokenJaccardThreshold: 1.1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClusteringOptions(minimumSharedEntities: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClusteringOptions(maxArticles: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new ArticleClusterer)->cluster(array_fill(0, 2, article('x', 'title')), new ClusteringOptions(maxArticles: 1)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new BriefingSelector)->select([], 0))->toThrow(InvalidArgumentException::class);
});
