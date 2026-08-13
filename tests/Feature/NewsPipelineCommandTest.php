<?php

use App\Data\ScrapedArticle;
use App\Enums\AnalysisStatus;
use App\Enums\BriefingEdition;
use App\Jobs\AnalyzeArticleJob;
use App\Jobs\ScrapeSourceJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Briefing;
use App\Models\Event;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\StubSourceScraper;

/*
 * El pipeline de punta a punta, sin red: spider de mentira y analizador falso.
 * Es el camino que corre el scheduler dos veces al día.
 */

beforeEach(function (): void {
    StubSourceScraper::reset();
    config(['newsscraper.ai.driver' => 'fake']);
    config(['newsscraper.scraping.spiders' => [StubSourceScraper::class]]);

    // El pipeline captura datos de mercado como última etapa; acá Yahoo va
    // falseado. `Http::preventStrayRequests()` (tests/Pest.php) se encarga de
    // que olvidarlo no pase inadvertido.
    Http::fake(['query1.finance.yahoo.com/*' => Http::response([
        'chart' => ['result' => [[
            'meta' => [
                'regularMarketPrice' => 6842.0,
                'regularMarketTime' => now()->timestamp,
                'exchangeTimezoneName' => 'UTC',
            ],
            'timestamp' => [now()->subDay()->timestamp, now()->timestamp],
            'indicators' => ['quote' => [['close' => [6800.0, 6842.0]]]],
        ]]],
    ])]);

    // Hora fija para que el corte del período no dependa de cuándo se corran
    // los tests: 20:00 en Chile, pasadas ambas ediciones del día.
    $this->travelTo(CarbonImmutable::now(config('newsscraper.briefing.timezone'))->setTime(20, 0));
});

/**
 * Argumentos de una corrida que sí puede publicar.
 *
 * Va con la edición de la tarde porque GenerateBriefingJob usa una ventana
 * semiabierta que termina en la hora de publicación: los artículos de las 14:00
 * caben en la edición de las 18:00, pero no en la de las 07:00.
 *
 * @return array<string, string|bool>
 */
function pipelineArguments(array $extra = []): array
{
    return ['--edition' => 'evening', '--spider' => StubSourceScraper::class, ...$extra];
}

function pipelineArticle(string $url, string $title): ScrapedArticle
{
    return new ScrapedArticle(
        url: $url,
        title: $title,
        author: 'Redacción',
        publishedAt: now()->subHours(6)->toDateTimeImmutable(),
        excerpt: 'Bajada del artículo.',
        content: 'Cuerpo suficiente para que el analizador tenga qué leer.',
    );
}

function twoSourcesCoveringTheSameStory(): void
{
    $first = Source::factory()->create(['slug' => 'diario-financiero', 'is_active' => true]);
    $second = Source::factory()->create(['slug' => 'pulso', 'is_active' => true]);

    StubSourceScraper::returns($first->slug, [
        pipelineArticle('https://df.cl/tpm-agosto', 'Banco Central mantiene la tasa de política monetaria en 4,75%'),
    ]);

    StubSourceScraper::returns($second->slug, [
        pipelineArticle('https://latercera.com/pulso/tpm-agosto', 'Banco Central mantiene la tasa de política monetaria'),
    ]);
}

it('recolecta, analiza, agrupa y publica en una sola corrida', function () {
    twoSourcesCoveringTheSameStory();

    $this->artisan('news:pipeline', pipelineArguments())->assertSuccessful();

    expect(Article::query()->count())->toBe(2)
        ->and(Article::query()->where('analysis_status', AnalysisStatus::Completed)->count())->toBe(2)
        // Los dos medios cubren la misma historia: un solo acontecimiento.
        ->and(Event::query()->count())->toBe(1)
        ->and(Event::query()->sole()->articles_count)->toBe(2);

    $briefing = Briefing::query()->with('events')->sole();

    expect($briefing->events)->toHaveCount(1)
        ->and($briefing->events->first()->title)->toContain('Banco Central');
});

it('no analiza dos veces el mismo artículo', function () {
    twoSourcesCoveringTheSameStory();

    $this->artisan('news:pipeline', pipelineArguments())->assertSuccessful();

    expect(Analysis::query()->count())->toBe(2);
});

it('la recolección del pipeline no encola análisis por su cuenta', function () {
    twoSourcesCoveringTheSameStory();
    Bus::fake([AnalyzeArticleJob::class]);

    $source = Source::query()->where('slug', 'diario-financiero')->sole();

    // El pipeline analiza en orden en su propia etapa; si la recolección además
    // encolara, el mismo artículo se analizaría dos veces.
    dispatch_sync(new ScrapeSourceJob($source->id, StubSourceScraper::class, queueAnalysis: false));

    expect(Article::query()->count())->toBe(1);
    Bus::assertNotDispatched(AnalyzeArticleJob::class);
});

it('es idempotente de punta a punta', function () {
    twoSourcesCoveringTheSameStory();

    $this->artisan('news:pipeline', pipelineArguments())->assertSuccessful();
    $this->artisan('news:pipeline', pipelineArguments())->assertSuccessful();

    expect(Article::query()->count())->toBe(2)
        ->and(Event::query()->count())->toBe(1)
        ->and(Briefing::query()->count())->toBe(1);
});

it('sigue adelante cuando una fuente se cae', function () {
    twoSourcesCoveringTheSameStory();
    StubSourceScraper::$shouldFail = true;

    $this->artisan('news:pipeline', pipelineArguments())->assertFailed();

    // Sin artículos no hay edición, pero el fallo quedó registrado por fuente y
    // el comando terminó ordenadamente en vez de reventar.
    expect(Briefing::query()->count())->toBe(0)
        ->and(Source::query()->where('failure_count', '>', 0)->count())->toBe(2);
});

it('no reescribe una edición que ya estaba publicada', function () {
    // Una edición de la tarde ya en la base, como la deja el seeder de demo.
    // La fecha editorial es el día en Chile, no en UTC: a las 20:00 de Chile ya
    // es el día siguiente en UTC, y usar now() acá crearía la edición de mañana.
    $existing = Briefing::factory()->create([
        'edition' => BriefingEdition::Evening,
        'published_on' => CarbonImmutable::now(config('newsscraper.briefing.timezone'))->toDateString(),
        'published_at' => now()->subHour(),
    ]);

    Event::factory()->critical()->create(['first_seen_at' => now()->subHours(6)]);

    $this->artisan('news:pipeline', ['--edition' => 'evening', '--skip-scrape' => true])
        ->expectsOutputToContain('Edición ya publicada')
        ->assertSuccessful();

    // GenerateBriefingJob es idempotente por omisión: no toca lo ya publicado.
    expect(Briefing::query()->count())->toBe(1)
        ->and($existing->fresh()->published_at->equalTo($existing->published_at))->toBeTrue();
});

it('falla cuando no hay nada que publicar', function () {
    $this->artisan('news:pipeline', ['--edition' => 'evening', '--skip-scrape' => true])
        ->assertFailed();

    expect(Briefing::query()->count())->toBe(0);
});

it('rechaza una edición que no existe', function () {
    $this->artisan('news:pipeline', ['--edition' => 'madrugada'])->assertFailed();
});

it('puede saltarse la recolección y trabajar con lo que ya está en la base', function () {
    Article::factory()->pending()->create([
        'title' => 'Cobre supera los cinco dólares la libra',
        'published_at' => now()->subHours(6),
    ]);

    $this->artisan('news:pipeline', ['--edition' => 'evening', '--skip-scrape' => true])
        ->assertSuccessful();

    expect(Event::query()->count())->toBe(1)
        ->and(Briefing::query()->count())->toBe(1);
});

it('encola una recolección por fuente activa con news:scrape', function () {
    Bus::fake();
    Source::factory()->count(2)->create(['is_active' => true]);
    Source::factory()->create(['is_active' => false]);

    $this->artisan('news:scrape')->assertSuccessful();

    Bus::assertDispatchedTimes(ScrapeSourceJob::class, 2);
});
