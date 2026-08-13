<?php

namespace App\Services\Ai;

use App\Contracts\NewsAnalyzer;
use App\Data\AnalysisResult;
use App\Data\NewsArticleInput;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;

class FakeNewsAnalyzer implements NewsAnalyzer
{
    public function analyze(NewsArticleInput $article): AnalysisResult
    {
        return new AnalysisResult(
            summary: 'Análisis de prueba determinista para: '.$article->title,
            category: NewsCategory::Economy,
            relevance: RelevanceLevel::Medium,
            companies: ['Empresa de prueba'],
            people: ['Persona de prueba'],
            tags: ['prueba', 'determinista'],
            importanceExplanation: 'Resultado fijo para pruebas sin llamadas externas.',
            provider: 'fake',
            model: 'fake-news-analyzer',
            schemaVersion: '1.0',
        );
    }
}
