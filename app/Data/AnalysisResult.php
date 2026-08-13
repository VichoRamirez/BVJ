<?php

namespace App\Data;

use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;

readonly class AnalysisResult
{
    /**
     * @param  list<string>  $companies
     * @param  list<string>  $people
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public string $summary,
        public NewsCategory $category,
        public RelevanceLevel $relevance,
        public array $companies,
        public array $people,
        public array $tags,
        public string $importanceExplanation,
        public string $provider,
        public string $model,
        public string $schemaVersion,
        public array $rawResponse,
    ) {}
}
