<?php

namespace App\Data\Clustering;

readonly class ClusteringOptions
{
    public function __construct(
        public int $windowHours = 24,
        public float $tokenJaccardThreshold = 0.5,
        public int $minimumSharedEntities = 1,
        public int $maxArticles = 500,
    ) {
        if ($windowHours < 1 || $windowHours > 168 || $tokenJaccardThreshold <= 0 || $tokenJaccardThreshold > 1 || $minimumSharedEntities < 1 || $maxArticles < 1) {
            throw new \InvalidArgumentException('Invalid clustering options.');
        }
    }
}
