<?php

use App\Contracts\NewsAnalyzer;
use App\Data\AnalysisResult;
use App\Data\NewsArticleInput;
use App\Enums\AnalysisStatus;
use App\Enums\EntityType;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Exceptions\AnalysisValidationException;
use App\Exceptions\OllamaTransportException;
use App\Jobs\AnalyzeArticleJob;
use App\Models\Article;
use App\Models\Entity;

/*
 * Análisis por LLM. El analizador siempre es un doble: ningún test sale a la red.
 */

beforeEach(function (): void {
    config(['newsscraper.ai.driver' => 'fake']);
});

/**
 * Analizador que responde una vez y cuenta cuántas veces lo llamaron, para
 * poder comprobar el reintento.
 */
function analyzerReturning(AnalysisResult $result): object
{
    return new class($result) implements NewsAnalyzer
    {
        public int $calls = 0;

        public function __construct(private readonly AnalysisResult $result) {}

        public function analyze(NewsArticleInput $article): AnalysisResult
        {
            $this->calls++;

            return $this->result;
        }
    };
}

function analyzerThrowing(Throwable $exception): object
{
    return new class($exception) implements NewsAnalyzer
    {
        public int $calls = 0;

        public function __construct(private readonly Throwable $exception) {}

        public function analyze(NewsArticleInput $article): AnalysisResult
        {
            $this->calls++;

            throw $this->exception;
        }
    };
}

function analysisResult(): AnalysisResult
{
    return new AnalysisResult(
        summary: 'El Banco Central mantuvo la TPM en 4,75%.',
        category: NewsCategory::Monetary,
        relevance: RelevanceLevel::High,
        companies: ['Banco Central', 'Banco Central'],
        people: ['Rosanna Costa'],
        tags: ['tpm', 'política monetaria'],
        importanceExplanation: 'Ancla el costo del crédito para el resto del año.',
        provider: 'ollama',
        model: 'llama3.2:3b',
        schemaVersion: '1.0',
        rawResponse: ['summary' => 'El Banco Central mantuvo la TPM en 4,75%.'],
    );
}

it('persiste el análisis, las entidades y las etiquetas', function () {
    $article = Article::factory()->pending()->create();
    app()->instance(NewsAnalyzer::class, analyzerReturning(analysisResult()));

    dispatch_sync(new AnalyzeArticleJob($article->id));

    $article->refresh()->load(['analysis', 'entities', 'tags']);

    expect($article->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($article->analysis->category)->toBe(NewsCategory::Monetary)
        ->and($article->analysis->relevance)->toBe(RelevanceLevel::High)
        ->and($article->analysis->raw_response)->toBe(['summary' => 'El Banco Central mantuvo la TPM en 4,75%.'])
        ->and($article->entities->pluck('name')->all())->toEqualCanonicalizing(['Banco Central', 'Rosanna Costa'])
        ->and($article->tags->pluck('name')->all())->toEqualCanonicalizing(['tpm', 'política monetaria']);
});

it('normaliza las entidades repetidas en una sola fila', function () {
    $first = Article::factory()->pending()->create();
    $second = Article::factory()->pending()->create();

    app()->instance(NewsAnalyzer::class, analyzerReturning(analysisResult()));

    dispatch_sync(new AnalyzeArticleJob($first->id));
    dispatch_sync(new AnalyzeArticleJob($second->id));

    expect(Entity::query()->where('type', EntityType::Company)->count())->toBe(1)
        ->and(Entity::query()->count())->toBe(2);
});

it('reintenta una vez una respuesta mal formada antes de rendirse', function () {
    $article = Article::factory()->pending()->create();
    $analyzer = analyzerThrowing(new AnalysisValidationException(['summary' => ['El campo es obligatorio.']]));
    app()->instance(NewsAnalyzer::class, $analyzer);

    dispatch_sync(new AnalyzeArticleJob($article->id));

    expect($analyzer->calls)->toBe(2)
        ->and($article->fresh()->analysis_status)->toBe(AnalysisStatus::Failed)
        ->and($article->fresh()->analysis)->toBeNull();
});

it('relanza los fallos de transporte en vez de marcar el artículo fallido', function () {
    $article = Article::factory()->pending()->create();
    $analyzer = analyzerThrowing(new OllamaTransportException('No fue posible conectar con Ollama.'));

    // Se invoca handle() directamente, sin el pipeline de middleware:
    // ThrottlesExceptions captura la excepción para soltar el job de vuelta a la
    // cola, y aquí lo que se quiere comprobar es justamente que el job la lanza.
    expect(fn () => (new AnalyzeArticleJob($article->id))->handle($analyzer))
        ->toThrow(OllamaTransportException::class);

    // Un fallo de transporte no es culpa del artículo: no se reintenta el LLM en
    // el acto y el artículo queda pendiente para la próxima corrida.
    expect($analyzer->calls)->toBe(1)
        ->and($article->fresh()->analysis_status)->toBe(AnalysisStatus::Pending);
});

it('deja el artículo pendiente cuando el throttling suelta el job', function () {
    $article = Article::factory()->pending()->create();
    app()->instance(NewsAnalyzer::class, analyzerThrowing(new OllamaTransportException('No fue posible conectar con Ollama.')));

    dispatch_sync(new AnalyzeArticleJob($article->id));

    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Pending);
});

it('marca fallido un artículo sin texto que analizar', function () {
    $article = Article::factory()->pending()->create(['content' => null, 'excerpt' => null]);
    app()->instance(NewsAnalyzer::class, analyzerReturning(analysisResult()));

    dispatch_sync(new AnalyzeArticleJob($article->id));

    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Failed);
});

it('no vuelve a analizar un artículo ya completado', function () {
    $article = Article::factory()->analyzed()->create();
    $analyzer = analyzerReturning(analysisResult());
    app()->instance(NewsAnalyzer::class, $analyzer);

    dispatch_sync(new AnalyzeArticleJob($article->id));

    expect($analyzer->calls)->toBe(0);
});

it('guarda siempre la respuesta cruda junto al análisis parseado', function () {
    $article = Article::factory()->pending()->create();
    app()->instance(NewsAnalyzer::class, analyzerReturning(analysisResult()));

    dispatch_sync(new AnalyzeArticleJob($article->id));

    expect($article->fresh()->analysis->raw_response)->not->toBeEmpty();
});
