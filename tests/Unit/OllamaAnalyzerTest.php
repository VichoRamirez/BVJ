<?php

use App\Contracts\NewsAnalyzer;
use App\Data\NewsArticleInput;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Exceptions\AnalysisValidationException;
use App\Exceptions\OllamaConfigurationException;
use App\Exceptions\OllamaInvalidResponseException;
use App\Exceptions\OllamaNonRetryableStatusException;
use App\Exceptions\OllamaRetryableStatusException;
use App\Exceptions\OllamaTransportException;
use App\Services\Ai\FakeNewsAnalyzer;
use App\Services\Ai\OllamaAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function ollamaArticle(): NewsArticleInput
{
    return new NewsArticleInput('Título ñ', 'Contenido económico 🚀', 'Bajada');
}

function ollamaResponse(string $content): array
{
    return ['message' => ['role' => 'assistant', 'content' => $content], 'done' => true];
}

function ollamaAnalysisJson(): string
{
    return json_encode([
        'summary' => 'Resumen válido',
        'category' => 'economy',
        'relevance' => 'high',
        'companies' => [],
        'people' => [],
        'tags' => ['economía'],
        'importance_explanation' => 'Importa por su impacto.',
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'newsscraper.ai.driver' => 'ollama',
        'newsscraper.ai.ollama' => [
            'base_url' => 'http://127.0.0.1:11434',
            'model' => 'llama3.2:3b',
            'connect_timeout' => 1,
            'timeout' => 2,
            'retry_attempts' => 2,
            'retry_backoff' => 0,
            'max_response_bytes' => 1_048_576,
        ],
    ]);
});

test('sends the exact Ollama chat payload and parses the response', function () {
    Http::fake(['127.0.0.1:11434/api/chat' => Http::response(ollamaResponse(ollamaAnalysisJson()))]);

    $result = app(NewsAnalyzer::class)->analyze(ollamaArticle());

    expect($result->provider)->toBe('ollama')
        ->and($result->model)->toBe('llama3.2:3b')
        ->and($result->summary)->toBe('Resumen válido');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'http://127.0.0.1:11434/api/chat'
            && $data['model'] === 'llama3.2:3b'
            && $data['stream'] === false
            && $data['options']['temperature'] === 0
            && $data['format']['type'] === 'object'
            && $data['format']['additionalProperties'] === false
            && $data['format']['properties']['category']['enum'] === array_column(NewsCategory::cases(), 'value')
            && $data['format']['properties']['relevance']['enum'] === array_column(RelevanceLevel::cases(), 'value')
            && $data['messages'][0]['role'] === 'system'
            && $data['messages'][1]['role'] === 'user'
            && $request->header('Authorization') === []
            && str_contains($data['messages'][1]['content'], 'Contenido económico 🚀');
    });
});

test('does not send authorization headers for local Ollama', function () {
    Http::fake(['*' => Http::response(ollamaResponse(ollamaAnalysisJson()))]);

    app(NewsAnalyzer::class)->analyze(ollamaArticle());

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === []);
});

test('does not follow redirects', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1:11434/other'])]);

    expect(fn () => app(NewsAnalyzer::class)->analyze(ollamaArticle()))
        ->toThrow(OllamaNonRetryableStatusException::class);

    Http::assertSentCount(1);
});

test('retries retryable statuses', function (int $status) {
    Http::fakeSequence('127.0.0.1:11434/api/chat')
        ->push([], $status)
        ->push(ollamaResponse(ollamaAnalysisJson()));

    app(NewsAnalyzer::class)->analyze(ollamaArticle());

    Http::assertSentCount(2);
})->with([408, 429, 500, 502, 503, 504]);

test('does not retry non-retryable status responses', function (int $status) {
    Http::fake(['*' => Http::response([], $status)]);

    expect(fn () => app(NewsAnalyzer::class)->analyze(ollamaArticle()))
        ->toThrow(OllamaNonRetryableStatusException::class);

    Http::assertSentCount(1);
})->with([400, 404]);

test('throws a retryable status exception after exhausting retries', function () {
    Http::fake(['*' => Http::response([], 503)]);

    expect(fn () => app(NewsAnalyzer::class)->analyze(ollamaArticle()))
        ->toThrow(OllamaRetryableStatusException::class);

    Http::assertSentCount(2);
});

test('rejects missing or non-string message content without retrying', function (array $body) {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => app(NewsAnalyzer::class)->analyze(ollamaArticle()))
        ->toThrow(OllamaInvalidResponseException::class);

    Http::assertSentCount(1);
})->with([
    'missing message' => [[]],
    'non-string content' => [['message' => ['content' => ['invalid']]]],
]);

test('does not retry parser failures', function () {
    Http::fake(['*' => Http::response(ollamaResponse('{"invalid":true}'))]);

    expect(fn () => app(NewsAnalyzer::class)->analyze(ollamaArticle()))
        ->toThrow(AnalysisValidationException::class);

    Http::assertSentCount(1);
});

test('resolves the real adapter and protects fake outside local environments', function () {
    expect(app(NewsAnalyzer::class))->toBeInstanceOf(OllamaAnalyzer::class);

    config(['newsscraper.ai.driver' => 'fake']);
    expect(app(NewsAnalyzer::class))->toBeInstanceOf(FakeNewsAnalyzer::class);

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app()->make(NewsAnalyzer::class))
        ->toThrow(LogicException::class);
});

test('wraps connection failures without exposing request details', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => app(NewsAnalyzer::class)->analyze(ollamaArticle()))
        ->toThrow(OllamaTransportException::class);

    Http::assertSentCount(2);
});

test('rejects non-local, credentialed, https, hostname, and missing-port URLs', function (string $url) {
    config(['newsscraper.ai.ollama.base_url' => $url]);

    expect(fn () => app(NewsAnalyzer::class))
        ->toThrow(OllamaConfigurationException::class);
})->with([
    'arbitrary host' => 'http://example.test:11434',
    'https cloud' => 'https://ollama.com:443',
    'credentials' => 'http://user:secret@127.0.0.1:11434',
    'hostname' => 'http://localhost:11434',
    'missing port' => 'http://localhost',
]);

test('allows supported loopback IP literals', function (string $url) {
    config(['newsscraper.ai.ollama.base_url' => $url]);
    Http::fake(['*' => Http::response(ollamaResponse(ollamaAnalysisJson()))]);

    expect(app(NewsAnalyzer::class)->analyze(ollamaArticle())->provider)->toBe('ollama');
})->with([
    'ipv4' => 'http://127.0.0.1:11434',
    'ipv6' => 'http://[::1]:11434',
]);

test('closes non-success response bodies before retrying', function () {
    Http::fakeSequence('127.0.0.1:11434/api/chat')
        ->push([], 503)
        ->push(ollamaResponse(ollamaAnalysisJson()));

    app(NewsAnalyzer::class)->analyze(ollamaArticle());

    Http::assertSentCount(2);
});

test('rejects invalid Ollama configuration values', function (string $key, mixed $value) {
    config(["newsscraper.ai.ollama.$key" => $value]);

    expect(fn () => app(NewsAnalyzer::class))
        ->toThrow(OllamaConfigurationException::class);
})->with([
    'empty model' => ['model', ''],
    'zero connect timeout' => ['connect_timeout', 0],
    'timeout over cap' => ['timeout', 61],
    'zero attempts' => ['retry_attempts', 0],
    'attempts over cap' => ['retry_attempts', 6],
    'negative backoff' => ['retry_backoff', -1],
    'backoff over cap' => ['retry_backoff', 10_001],
]);

test('rejects oversized content length and chunked downloaded bodies', function () {
    config(['newsscraper.ai.ollama.max_response_bytes' => 10]);
    Http::fake(['*' => Http::response(ollamaResponse(ollamaAnalysisJson()), 200, ['Content-Length' => '100'])]);

    expect(fn () => app(NewsAnalyzer::class)->analyze(ollamaArticle()))
        ->toThrow(OllamaInvalidResponseException::class);

    Http::fake(['*' => Http::response(str_repeat('x', 11))]);
    expect(fn () => app(NewsAnalyzer::class)->analyze(ollamaArticle()))
        ->toThrow(OllamaInvalidResponseException::class);
});
