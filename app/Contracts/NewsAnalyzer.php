<?php

namespace App\Contracts;

use App\Data\AnalysisResult;
use App\Data\NewsArticleInput;

interface NewsAnalyzer
{
    public function analyze(NewsArticleInput $article): AnalysisResult;
}
