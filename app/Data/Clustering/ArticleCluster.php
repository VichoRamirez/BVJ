<?php

namespace App\Data\Clustering;

use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use DateTimeImmutable;

readonly class ArticleCluster
{
    /**
     * @param  list<ClusterableArticleData>  $members
     * @param  list<string>  $canonicalEntities  Union of normalized member entities.
     * @param  list<string>  $sharedEntities  Intersection present in at least two members.
     */
    public function __construct(
        public array $members,
        public NewsCategory $category,
        public string $representativeTitle,
        public int $distinctSourceCount,
        public RelevanceLevel $maxRelevance,
        public DateTimeImmutable $latestPublishedAt,
        public array $canonicalEntities,
        public array $sharedEntities,
        public string $representativeId,
    ) {}
}
