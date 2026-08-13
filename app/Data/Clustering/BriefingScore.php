<?php

namespace App\Data\Clustering;

readonly class BriefingScore
{
    public function __construct(public ArticleCluster $cluster, public int $score) {}
}
