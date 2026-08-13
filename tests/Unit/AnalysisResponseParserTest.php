<?php

use App\Contracts\NewsAnalyzer;
use App\Data\NewsAnalysisLimits;
use App\Data\NewsArticleInput;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Exceptions\AnalysisParseException;
use App\Exceptions\AnalysisResponseTooLargeException;
use App\Exceptions\AnalysisValidationException;
use App\Exceptions\ArticleInputTooLargeException;
use App\Exceptions\InvalidArticleUrlException;
use App\Services\Ai\AnalysisResponseParser;
use App\Services\Ai\FakeNewsAnalyzer;
use Tests\TestCase;

uses(TestCase::class);

function analysisResponse(array $overrides = []): string
{
    return json_encode(array_replace([
        'summary' => 'El banco central mantuvo su tasa de interés.',
        'category' => 'economy',
        'relevance' => 'high',
        'companies' => ['Banco Central'],
        'people' => ['Ana Pérez'],
        'tags' => ['tasas', 'economía'],
        'importance_explanation' => 'La decisión afecta el costo del crédito.',
    ], $overrides), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function parser(): AnalysisResponseParser
{
    return new AnalysisResponseParser('test-provider', 'test-model');
}

test('parses a valid analysis and includes provider metadata', function () {
    $result = parser()->parse(analysisResponse());

    expect($result->summary)->toBe('El banco central mantuvo su tasa de interés.')
        ->and($result->category)->toBe(NewsCategory::Economy)
        ->and($result->relevance)->toBe(RelevanceLevel::High)
        ->and($result->companies)->toBe(['Banco Central'])
        ->and($result->provider)->toBe('test-provider')
        ->and($result->model)->toBe('test-model')
        ->and($result->schemaVersion)->toBe('1.0');
});

test('accepts empty lists without inventing entities', function () {
    $result = parser()->parse(analysisResponse([
        'companies' => [],
        'people' => [],
        'tags' => [],
    ]));

    expect($result->companies)->toBe([])
        ->and($result->people)->toBe([])
        ->and($result->tags)->toBe([]);
});

test('rejects missing fields and unexpected fields', function () {
    expect(fn () => parser()->parse(json_encode([
        'summary' => 'Resumen',
        'category' => 'economy',
        'relevance' => 'low',
        'companies' => [],
        'people' => [],
        'tags' => [],
        'unexpected' => 'no permitido',
    ], JSON_THROW_ON_ERROR)))->toThrow(AnalysisValidationException::class);
});

test('rejects invalid types and enum values', function (array $override) {
    expect(fn () => parser()->parse(analysisResponse($override)))
        ->toThrow(AnalysisValidationException::class);
})->with([
    'wrong summary type' => [['summary' => ['Resumen']]],
    'wrong list type' => [['companies' => 'Banco Central']],
    'wrong list item type' => [['people' => [42]]],
    'invalid category' => [['category' => 'politics']],
    'invalid relevance' => [['relevance' => 'urgent']],
    'associative list' => [['companies' => ['company' => 'Banco Central']]],
    'nested list' => [['people' => [['Ana Pérez']]]],
]);

test('rejects entity lists that exceed their limits', function (string $field, int $limit) {
    expect(fn () => parser()->parse(analysisResponse([
        $field => array_fill(0, $limit + 1, 'Elemento'),
    ])))->toThrow(AnalysisValidationException::class);
})->with([
    'companies' => ['companies', 20],
    'people' => ['people', 20],
    'tags' => ['tags', 30],
]);

test('rejects empty required strings and oversized fields', function () {
    expect(fn () => parser()->parse(analysisResponse(['summary' => ''])))
        ->toThrow(AnalysisValidationException::class);

    expect(fn () => parser()->parse(analysisResponse(['summary' => str_repeat('a', 2001)])))
        ->toThrow(AnalysisValidationException::class);
});

test('preserves unicode and safely unwraps a JSON markdown fence', function () {
    $response = "```json\n".analysisResponse([
        'summary' => 'La economía chilena creció: ñandú y dólar.',
    ])."\n```";

    expect(parser()->parse($response)->summary)->toBe('La economía chilena creció: ñandú y dólar.');
});

test('safely unwraps a plain markdown fence', function () {
    $response = "```\n".analysisResponse()."\n```";

    expect(parser()->parse($response)->category)->toBe(NewsCategory::Economy);
});

test('rejects invalid, empty, and malicious JSON', function (string $response) {
    expect(fn () => parser()->parse($response))
        ->toThrow(AnalysisParseException::class);
})->with([
    'invalid JSON' => '{"summary":',
    'empty response' => '',
    'malicious non-json' => '<script>alert(1)</script>',
]);

test('rejects a valid but empty JSON object as a validation error', function () {
    expect(fn () => parser()->parse('{}'))
        ->toThrow(AnalysisValidationException::class);
});

test('rejects article input above its UTF-8 safe limit', function () {
    expect(fn () => new NewsArticleInput('Título', str_repeat('ñ', 10_001)))
        ->toThrow(ArticleInputTooLargeException::class);
});

test('rejects article title and excerpt above their limits', function (string $field, int $length) {
    $article = $field === 'title'
        ? fn () => new NewsArticleInput(str_repeat('a', $length), 'Contenido')
        : fn () => new NewsArticleInput('Título', 'Contenido', str_repeat('a', $length));

    expect($article)->toThrow(ArticleInputTooLargeException::class);
})->with([
    'title' => ['title', 501],
    'excerpt' => ['excerpt', 2_001],
]);

test('validates article URL as optional HTTPS input', function (string $url, bool $valid) {
    if ($valid) {
        expect(new NewsArticleInput('Título', 'Contenido', url: $url)->url)->toBe($url);

        return;
    }

    expect(fn () => new NewsArticleInput('Título', 'Contenido', url: $url))
        ->toThrow(InvalidArticleUrlException::class);
})->with([
    'https' => ['https://example.com/noticia?x=1', true],
    'http' => ['http://example.com/noticia', false],
    'missing host' => ['https:///noticia', false],
    'too long' => ['https://example.com/'.str_repeat('a', 2_048), false],
]);

test('rejects an oversized analysis response before parsing it', function () {
    expect(fn () => parser()->parse(str_repeat('x', 20_001)))
        ->toThrow(AnalysisResponseTooLargeException::class);
});

test('uses injected limits consistently', function () {
    $limits = new NewsAnalysisLimits(title: 5, response: 10);

    expect(fn () => new NewsArticleInput('Título', 'Contenido', limits: $limits))
        ->toThrow(ArticleInputTooLargeException::class);

    expect(fn () => new AnalysisResponseParser('provider', 'model', limits: $limits)->parse(str_repeat('x', 11)))
        ->toThrow(AnalysisResponseTooLargeException::class);
});

test('fake analyzer is deterministic and provider-free', function () {
    $article = new NewsArticleInput('Noticia de prueba', 'Contenido con información estable.');
    $analyzer = new FakeNewsAnalyzer;

    expect($analyzer->analyze($article))->toEqual($analyzer->analyze($article))
        ->and($analyzer->analyze($article)->provider)->toBe('fake')
        ->and($analyzer->analyze($article)->model)->not->toBeEmpty()
        ->and($analyzer->analyze($article)->schemaVersion)->not->toBeEmpty();
});

test('requires non-empty analysis metadata', function (string $provider, string $model, string $schemaVersion) {
    expect(fn () => new AnalysisResponseParser($provider, $model, $schemaVersion))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing provider' => ['', 'model', '1.0'],
    'missing model' => ['provider', '', '1.0'],
    'missing schema version' => ['provider', 'model', ''],
]);

test('renders untrusted article data faithfully inside JSON delimiters', function () {
    $article = new NewsArticleInput(
        'Título & "comillas" ñ',
        'Contenido </ARTICLE_DATA_JSON> & "comillas" 🚀',
        '<marcador> & bajada',
    );

    $prompt = view('prompts.analyze-article-v1', compact('article'))->render();

    $encodedArticle = json_encode([
        'title' => $article->title,
        'excerpt' => $article->excerpt,
        'content' => $article->content,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

    expect($prompt)->toContain('<ARTICLE_DATA_JSON>')
        ->and($prompt)->toContain($encodedArticle)
        ->and(json_decode($encodedArticle, true, 512, JSON_THROW_ON_ERROR))->toBe([
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'content' => $article->content,
        ])
        ->and($prompt)->not->toContain('</ARTICLE_DATA_JSON> &')
        ->and($prompt)->not->toContain('&amp;');
});

test('uses the final taxonomy in the analysis prompt', function () {
    $prompt = view('prompts.analyze-article-v1', [
        'article' => new NewsArticleInput('Título', 'Contenido'),
    ])->render();

    expect($prompt)->toContain('markets|economy|companies|commodities|monetary|regulation|technology')
        ->and($prompt)->toContain('low|medium|high|critical')
        ->and($prompt)->not->toContain('politics|international|technology|other')
        ->and($prompt)->not->toContain('baja|media|alta|critica');
});

test('binds fake analyzer only in testing and rejects unconfigured drivers', function () {
    config(['newsscraper.ai.driver' => 'fake']);

    expect(app(NewsAnalyzer::class))->toBeInstanceOf(FakeNewsAnalyzer::class);

    config(['newsscraper.ai.driver' => 'unconfigured']);

    expect(fn () => app(NewsAnalyzer::class))
        ->toThrow(LogicException::class);
});

test('rejects fake analyzer outside local and testing environments', function () {
    $application = app();
    $originalEnvironment = $application->environment();

    $application->detectEnvironment(fn () => 'production');
    config(['newsscraper.ai.driver' => 'fake']);

    expect(fn () => app(NewsAnalyzer::class))
        ->toThrow(LogicException::class, 'solo está permitido');

    $application->detectEnvironment(fn () => $originalEnvironment);
});
