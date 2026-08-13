<?php

use App\Contracts\NewsAnalyzer;
use App\Data\AnalysisResult;
use App\Data\NewsArticleInput;
use App\Enums\AnalysisStatus;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Models\Article;
use App\Models\Source;

/*
 * `news:analyze` es la única forma de recuperar un análisis fallido: ni
 * `news:pipeline` ni un nuevo scraping vuelven a encolar un artículo que no
 * está `pending`. Ningún test toca la red: el analizador va falseado y cuenta
 * cuántas veces lo llamaron, que es lo que protege la cuota de la API.
 */

function countingAnalyzer(): object
{
    $analyzer = new class implements NewsAnalyzer
    {
        /** @var list<string> */
        public array $seen = [];

        public function analyze(NewsArticleInput $article): AnalysisResult
        {
            $this->seen[] = $article->title;

            return new AnalysisResult(
                summary: 'Resumen',
                category: NewsCategory::Economy,
                relevance: RelevanceLevel::High,
                companies: [],
                people: [],
                tags: [],
                importanceExplanation: 'Importancia',
                provider: 'fake',
                model: 'test-model',
                schemaVersion: '1.0',
                rawResponse: ['content' => 'fake'],
            );
        }
    };

    app()->instance(NewsAnalyzer::class, $analyzer);

    return $analyzer;
}

it('analiza los artículos pendientes', function () {
    $analyzer = countingAnalyzer();
    $article = Article::factory()->pending()->create(['title' => 'Nota pendiente']);

    $this->artisan('news:analyze')->assertSuccessful();

    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($analyzer->seen)->toBe(['Nota pendiente']);
});

it('no vuelve a analizar lo que ya está completado', function () {
    $analyzer = countingAnalyzer();
    Article::factory()->analyzed()->create();

    $this->artisan('news:analyze')->assertSuccessful();

    // Reanalizar lo ya analizado sería gastar cuota de la API para nada.
    expect($analyzer->seen)->toBeEmpty();
});

it('ignora los fallidos salvo que se pidan explícitamente', function () {
    $analyzer = countingAnalyzer();
    Article::factory()->create(['analysis_status' => AnalysisStatus::Failed, 'title' => 'Falló antes']);

    $this->artisan('news:analyze')->assertSuccessful();

    expect($analyzer->seen)->toBeEmpty();
});

it('reintenta los fallidos con --retry-failed y los deja completados', function () {
    $analyzer = countingAnalyzer();
    $fallido = Article::factory()->create(['analysis_status' => AnalysisStatus::Failed, 'title' => 'Falló antes']);
    $pendiente = Article::factory()->pending()->create(['title' => 'Nota pendiente']);

    $this->artisan('news:analyze --retry-failed')->assertSuccessful();

    expect($fallido->fresh()->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($pendiente->fresh()->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($analyzer->seen)->toHaveCount(2);
});

it('con --only-failed deja en paz a los pendientes', function () {
    $analyzer = countingAnalyzer();
    Article::factory()->create(['analysis_status' => AnalysisStatus::Failed, 'title' => 'Falló antes']);
    $pendiente = Article::factory()->pending()->create(['title' => 'Nota pendiente']);

    $this->artisan('news:analyze --only-failed')->assertSuccessful();

    expect($analyzer->seen)->toBe(['Falló antes'])
        ->and($pendiente->fresh()->analysis_status)->toBe(AnalysisStatus::Pending);
});

it('acota el gasto con --limit', function () {
    $analyzer = countingAnalyzer();
    Article::factory()->count(5)->pending()->create();

    $this->artisan('news:analyze --limit=2')->assertSuccessful();

    expect($analyzer->seen)->toHaveCount(2)
        ->and(Article::where('analysis_status', AnalysisStatus::Pending)->count())->toBe(3);
});

it('filtra por fuente', function () {
    $analyzer = countingAnalyzer();
    $elegida = Source::factory()->create(['slug' => 'diario-financiero']);
    Article::factory()->pending()->create(['source_id' => $elegida->id, 'title' => 'De la fuente elegida']);
    Article::factory()->pending()->create(['title' => 'De otra fuente']);

    $this->artisan('news:analyze --source=diario-financiero')->assertSuccessful();

    expect($analyzer->seen)->toBe(['De la fuente elegida']);
});

it('falla con un mensaje claro si la fuente no existe', function () {
    countingAnalyzer();

    $this->artisan('news:analyze --source=no-existe')->assertFailed();
});
