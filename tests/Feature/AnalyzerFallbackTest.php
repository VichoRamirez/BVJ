<?php

use App\Contracts\NewsAnalyzer;
use App\Data\AnalysisResult;
use App\Data\AnalyzerCandidate;
use App\Data\NewsArticleInput;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Exceptions\AnalysisValidationException;
use App\Exceptions\NoAnalyzerAvailableException;
use App\Exceptions\OllamaConfigurationException;
use App\Exceptions\OpenRouterInvalidResponseException;
use App\Exceptions\OpenRouterNonRetryableStatusException;
use App\Exceptions\OpenRouterRetryableStatusException;
use App\Exceptions\OpenRouterTransportException;
use App\Services\Ai\AnalyzerCircuitBreaker;
use App\Services\Ai\FakeNewsAnalyzer;
use App\Services\Ai\FallbackNewsAnalyzer;
use App\Services\Ai\OllamaAnalyzer;
use App\Services\Ai\OpenRouterAnalyzer;
use Illuminate\Support\Facades\Http;

/*
 * Cadena de respaldo entre modelos. Ninguna llamada sale a la red.
 */

function analyzableArticle(): NewsArticleInput
{
    return new NewsArticleInput('Banco Central mantiene la TPM', 'Cuerpo del artículo para analizar.');
}

function fallbackResultFrom(string $model): AnalysisResult
{
    return new AnalysisResult(
        summary: 'Resumen', category: NewsCategory::Monetary, relevance: RelevanceLevel::High,
        companies: [], people: [], tags: [], importanceExplanation: 'Importa',
        provider: 'openrouter', model: $model, schemaVersion: '1.0', rawResponse: ['content' => '{}'],
    );
}

/**
 * Eslabón que responde, falla o cuenta cuántas veces lo llamaron.
 */
function fallbackCandidate(string $label, ?Throwable $throws = null, ?object $spy = null): AnalyzerCandidate
{
    return new AnalyzerCandidate($label, function () use ($label, $throws, $spy): NewsAnalyzer {
        return new class($label, $throws, $spy) implements NewsAnalyzer
        {
            public function __construct(private string $label, private ?Throwable $throws, private ?object $spy) {}

            public function analyze(NewsArticleInput $article): AnalysisResult
            {
                if ($this->spy !== null) {
                    $this->spy->calls[] = $this->label;
                }

                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return fallbackResultFrom($this->label);
            }
        };
    });
}

it('usa el primer modelo que responde', function () {
    $chain = new FallbackNewsAnalyzer([
        fallbackCandidate('a', new OpenRouterRetryableStatusException('429')),
        fallbackCandidate('b'),
        fallbackCandidate('c'),
    ]);

    expect($chain->analyze(analyzableArticle())->model)->toBe('b');
});

it('salta un eslabón que ni siquiera se puede construir', function () {
    // Es el caso real: Ollama configurado pero no instalado, o falta la API key.
    $chain = new FallbackNewsAnalyzer([
        new AnalyzerCandidate('roto', fn () => throw new OllamaConfigurationException('sin configurar')),
        fallbackCandidate('respaldo'),
    ]);

    expect($chain->analyze(analyzableArticle())->model)->toBe('respaldo');
});

it('no construye los eslabones posteriores si el primero responde', function () {
    $spy = new stdClass;
    $spy->calls = [];

    $chain = new FallbackNewsAnalyzer([
        fallbackCandidate('primero', spy: $spy),
        new AnalyzerCandidate('nunca', fn () => throw new RuntimeException('no debería construirse')),
    ]);

    expect($chain->analyze(analyzableArticle())->model)->toBe('primero')
        ->and($spy->calls)->toBe(['primero']);
});

it('falla con un error de indisponibilidad cuando se agota la cadena', function () {
    $chain = new FallbackNewsAnalyzer([
        fallbackCandidate('a', new OpenRouterTransportException('sin conexión')),
        fallbackCandidate('b', new OpenRouterRetryableStatusException('503')),
    ]);

    expect(fn () => $chain->analyze(analyzableArticle()))->toThrow(NoAnalyzerAvailableException::class);
});

it('no cambia de modelo cuando la respuesta viene mal formada', function () {
    $spy = new stdClass;
    $spy->calls = [];

    $chain = new FallbackNewsAnalyzer([
        fallbackCandidate('a', new AnalysisValidationException(['summary' => ['requerido']]), $spy),
        fallbackCandidate('b', spy: $spy),
    ]);

    // Un JSON que no cumple el esquema suele ser culpa del prompt: taparlo con
    // otro modelo esconde el problema justo cuando hay que verlo.
    expect(fn () => $chain->analyze(analyzableArticle()))->toThrow(AnalysisValidationException::class)
        ->and($spy->calls)->toBe(['a']);
});

it('sí cambia de modelo ante respuesta mal formada si se activa por configuración', function () {
    config(['newsscraper.ai.fallback.on_invalid_response' => true]);

    $chain = new FallbackNewsAnalyzer([
        fallbackCandidate('a', new AnalysisValidationException(['summary' => ['requerido']])),
        fallbackCandidate('b'),
    ]);

    expect($chain->analyze(analyzableArticle())->model)->toBe('b');
});

it('deja de intentar un modelo caído tras varios fallos seguidos', function () {
    config(['newsscraper.ai.fallback.circuit_breaker_failures' => 2]);

    $spy = new stdClass;
    $spy->calls = [];

    $chain = new FallbackNewsAnalyzer([
        fallbackCandidate('caido', new OpenRouterTransportException('sin conexión'), $spy),
        fallbackCandidate('respaldo', spy: $spy),
    ]);

    foreach (range(1, 4) as $ignored) {
        $chain->analyze(analyzableArticle());
    }

    // Sin cortocircuito serían 4 intentos al modelo caído, uno por artículo, cada
    // uno esperando su timeout completo.
    expect(collect($spy->calls)->filter(fn (string $c): bool => $c === 'caido')->count())->toBe(2);
});

it('restablece el modelo cuando vuelve a responder', function () {
    $breaker = new AnalyzerCircuitBreaker;

    $breaker->recordFailure('openrouter:x');
    expect($breaker->failures('openrouter:x'))->toBe(1);

    $breaker->recordSuccess('openrouter:x');
    expect($breaker->failures('openrouter:x'))->toBe(0)
        ->and($breaker->isOpen('openrouter:x'))->toBeFalse();
});

it('registra en el análisis qué modelo terminó respondiendo', function () {
    $chain = new FallbackNewsAnalyzer([
        fallbackCandidate('ollama:llama3.2', new OpenRouterTransportException('sin conexión')),
        fallbackCandidate('openrouter:gemma'),
    ]);

    // `analyses.provider` y `analyses.model` dejan la auditoría hecha.
    expect($chain->analyze(analyzableArticle())->model)->toBe('openrouter:gemma');
});

it('rechaza una cadena vacía', function () {
    expect(fn () => new FallbackNewsAnalyzer([]))->toThrow(InvalidArgumentException::class);
});

/*
 * Construcción de la cadena desde la configuración.
 */

it('arma la cadena declarada en config con NEWS_AI_DRIVER=chain', function () {
    config([
        'newsscraper.ai.driver' => 'chain',
        'newsscraper.ai.chain' => [
            ['driver' => 'fake'],
            ['driver' => 'openrouter', 'model' => 'x:free'],
        ],
    ]);

    expect(app(NewsAnalyzer::class))->toBeInstanceOf(FallbackNewsAnalyzer::class);
});

it('resuelve openrouter como driver único', function () {
    config(['newsscraper.ai.driver' => 'openrouter', 'newsscraper.ai.openrouter.api_key' => 'sk-test']);

    expect(app(NewsAnalyzer::class))->toBeInstanceOf(OpenRouterAnalyzer::class);
});

it('sigue resolviendo ollama y fake como antes', function () {
    config(['newsscraper.ai.driver' => 'ollama']);
    expect(app(NewsAnalyzer::class))->toBeInstanceOf(OllamaAnalyzer::class);

    config(['newsscraper.ai.driver' => 'fake']);
    expect(app(NewsAnalyzer::class))->toBeInstanceOf(FakeNewsAnalyzer::class);
});

it('la cadena sobrevive a un eslabón sin API key y usa el siguiente', function () {
    config([
        'newsscraper.ai.driver' => 'chain',
        'newsscraper.ai.openrouter.api_key' => null,
        'newsscraper.ai.chain' => [
            ['driver' => 'openrouter', 'model' => 'x:free'],
            ['driver' => 'fake'],
        ],
    ]);

    expect(app(NewsAnalyzer::class)->analyze(analyzableArticle())->provider)->toBe('fake');
});

/*
 * Adaptador de OpenRouter.
 */

beforeEach(function (): void {
    config(['newsscraper.ai.openrouter.api_key' => 'sk-test-key']);
});

function fakeOpenRouter(mixed $body, int $status = 200): void
{
    Http::fake(['openrouter.ai/*' => Http::response($body, $status)]);
}

function openRouterEnvelope(array $payload): array
{
    return ['choices' => [['message' => ['content' => json_encode($payload)]]]];
}

function validAnalysisPayload(): array
{
    return [
        'summary' => 'El Banco Central mantuvo la TPM en 4,75%.',
        'category' => NewsCategory::Monetary->value,
        'relevance' => RelevanceLevel::High->value,
        'companies' => ['Banco Central'],
        'people' => ['Rosanna Costa'],
        'tags' => ['tpm'],
        'importance_explanation' => 'Ancla el costo del crédito.',
    ];
}

it('analiza contra OpenRouter y devuelve el resultado parseado', function () {
    fakeOpenRouter(openRouterEnvelope(validAnalysisPayload()));

    $result = new OpenRouterAnalyzer('google/gemma-4-26b-a4b-it:free')->analyze(analyzableArticle());

    expect($result->provider)->toBe('openrouter')
        ->and($result->model)->toBe('google/gemma-4-26b-a4b-it:free')
        ->and($result->category)->toBe(NewsCategory::Monetary)
        ->and($result->rawResponse)->not->toBeEmpty();
});

it('manda la API key como bearer y exige el esquema JSON', function () {
    fakeOpenRouter(openRouterEnvelope(validAnalysisPayload()));

    new OpenRouterAnalyzer('x:free')->analyze(analyzableArticle());

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer sk-test-key')
            && $request['response_format']['type'] === 'json_schema'
            && $request['model'] === 'x:free';
    });
});

it('trata el 429 de los modelos gratuitos como indisponibilidad', function () {
    fakeOpenRouter([], 429);

    expect(fn () => new OpenRouterAnalyzer('x:free')->analyze(analyzableArticle()))
        ->toThrow(OpenRouterRetryableStatusException::class);
});

it('trata un 401 como error propio, no como indisponibilidad', function () {
    fakeOpenRouter([], 401);

    // Una key inválida no se arregla cambiando de modelo: hay que verla.
    expect(fn () => new OpenRouterAnalyzer('x:free')->analyze(analyzableArticle()))
        ->toThrow(OpenRouterNonRetryableStatusException::class);
});

it('rechaza una respuesta de OpenRouter sin contenido', function () {
    fakeOpenRouter(['choices' => []]);

    expect(fn () => new OpenRouterAnalyzer('x:free')->analyze(analyzableArticle()))
        ->toThrow(OpenRouterInvalidResponseException::class);
});

it('nunca expone la API key en el mensaje de error', function () {
    fakeOpenRouter([], 500);

    try {
        new OpenRouterAnalyzer('x:free')->analyze(analyzableArticle());
    } catch (Throwable $exception) {
        expect($exception->getMessage())->not->toContain('sk-test-key');
    }
});
