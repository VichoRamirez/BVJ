<?php

namespace App\Data;

readonly class NewsAnalysisLimits
{
    public function __construct(
        public int $title = 500,
        public int $excerpt = 2_000,
        public int $content = 10_000,
        public int $url = 2_048,
        public int $response = 20_000,
        public int $companies = 20,
        public int $people = 20,
        public int $tags = 30,
    ) {}
}
