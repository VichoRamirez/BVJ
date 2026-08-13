<?php

namespace App\Data\Clustering;

use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use DateTimeImmutable;

readonly class ClusterableArticleData
{
    /**
     * @param  list<string>  $entities
     * @param  string  $sourceKey  Stable identifier of the article source.
     */
    public function __construct(
        public string $id,
        public string $title,
        public DateTimeImmutable $publishedAt,
        public NewsCategory $category,
        public RelevanceLevel $relevance,
        public array $entities,
        public string $sourceKey,
    ) {
        if (trim($id) === '' || trim($title) === '' || trim($sourceKey) === '') {
            throw new \InvalidArgumentException('id, title and sourceKey must not be empty.');
        }
        if (! array_is_list($entities) || array_filter($entities, static fn ($entity): bool => ! is_string($entity)) !== []) {
            throw new \InvalidArgumentException('entities must be a list of strings.');
        }
        if (array_filter($entities, static fn (string $entity): bool => trim($entity) === '') !== []) {
            throw new \InvalidArgumentException('entities must not contain blank values.');
        }
    }
}
