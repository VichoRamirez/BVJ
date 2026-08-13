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
        $payload = [
            'summary' => 'Análisis de prueba determinista para: '.$article->title,
            'category' => NewsCategory::Economy->value,
            'relevance' => RelevanceLevel::Medium->value,
            'companies' => ['Empresa de prueba'],
            'people' => ['Persona de prueba'],
            'tags' => ['prueba', 'determinista'],
            'importance_explanation' => 'Resultado fijo para pruebas sin llamadas externas.',
        ];

        return new AnalysisResult(
            summary: $payload['summary'],
            category: NewsCategory::Economy,
            relevance: RelevanceLevel::Medium,
            companies: $payload['companies'],
            people: $payload['people'],
            tags: $payload['tags'],
            importanceExplanation: $payload['importance_explanation'],
            provider: 'fake',
            model: 'fake-news-analyzer',
            schemaVersion: '1.0',
            rawResponse: $payload,
        );
    }
}
