<?php

namespace App\Services\Clustering;

use App\Data\Clustering\ArticleCluster;
use App\Data\Clustering\BriefingScore;

final class BriefingScorer
{
    public function __construct(private readonly int $sourceBonus = 1, private readonly int $maxSourceBonus = 3)
    {
        if ($sourceBonus < 0 || $maxSourceBonus < 0) {
            throw new \InvalidArgumentException('Score bonuses must not be negative.');
        }
    }

    public function score(ArticleCluster $cluster): BriefingScore
    {
        // Reproducible integer score: base relevance 1..4 plus capped source diversity.
        $bonusSources = min(max(0, $cluster->distinctSourceCount - 1), $this->maxSourceBonus);

        return new BriefingScore($cluster, $cluster->maxRelevance->weight() + ($bonusSources * $this->sourceBonus));
    }
}
