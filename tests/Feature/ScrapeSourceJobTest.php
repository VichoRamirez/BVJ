<?php

use App\Data\ScrapedArticle;
use App\Enums\AnalysisStatus;
use App\Jobs\AnalyzeArticleJob;
use App\Jobs\ScrapeSourceJob;
use App\Models\Article;
use App\Models\Source;
use Illuminate\Support\Facades\Bus;
use Tests\Fixtures\StubSourceScraper;

/*
 * Recolección. Ningún test toca la red: el spider es un stub.
 */

beforeEach(function (): void {
    StubSourceScraper::reset();
    Bus::fake([AnalyzeArticleJob::class]);
});

function scrapedArticle(string $url, string $title = 'Cobre supera los cinco dólares la libra'): ScrapedArticle
{
    return new ScrapedArticle(
        url: $url,
        title: $title,
        author: 'Redacción',
        publishedAt: new DateTimeImmutable('-2 hours'),
        excerpt: 'Bajada del artículo.',
        content: 'Cuerpo mínimo necesario para analizar el artículo.',
    );
}

function sourceWithStub(array $attributes = []): Source
{
    return Source::factory()->create([
        'spider_class' => StubSourceScraper::class,
        'is_active' => true,
        ...$attributes,
    ]);
}

it('persiste los artículos recolectados y los encola para análisis', function () {
    $source = sourceWithStub();
    StubSourceScraper::returns($source->slug, [
        scrapedArticle('https://df.cl/cobre-cinco-dolares'),
        scrapedArticle('https://df.cl/imacec-julio', 'El Imacec de julio sorprende al alza'),
    ]);

    dispatch_sync(new ScrapeSourceJob($source->id));

    expect(Article::query()->count())->toBe(2)
        ->and(Article::query()->where('analysis_status', AnalysisStatus::Pending)->count())->toBe(2)
        ->and($source->fresh()->last_scraped_at)->not->toBeNull();

    Bus::assertDispatchedTimes(AnalyzeArticleJob::class, 2);
});

it('no duplica artículos al volver a recolectar la misma fuente', function () {
    $source = sourceWithStub();
    StubSourceScraper::returns($source->slug, [
        // La segunda URL es la misma con parámetros de campaña y barra final:
        // CanonicalUrl las colapsa en la misma fila.
        scrapedArticle('https://df.cl/cobre-cinco-dolares'),
        scrapedArticle('https://www.df.cl/cobre-cinco-dolares/?utm_source=newsletter'),
    ]);

    dispatch_sync(new ScrapeSourceJob($source->id));
    dispatch_sync(new ScrapeSourceJob($source->id));

    expect(Article::query()->count())->toBe(1);
});

it('no reencola un artículo que ya fue analizado', function () {
    $source = sourceWithStub();
    StubSourceScraper::returns($source->slug, [scrapedArticle('https://df.cl/cobre-cinco-dolares')]);

    dispatch_sync(new ScrapeSourceJob($source->id));
    Article::query()->update(['analysis_status' => AnalysisStatus::Completed]);
    dispatch_sync(new ScrapeSourceJob($source->id));

    expect(Article::query()->sole()->analysis_status)->toBe(AnalysisStatus::Completed);
    Bus::assertDispatchedTimes(AnalyzeArticleJob::class, 1);
});

it('registra el fallo en la fuente sin lanzar la excepción', function () {
    $source = sourceWithStub(['failure_count' => 1]);
    StubSourceScraper::$shouldFail = true;

    dispatch_sync(new ScrapeSourceJob($source->id));

    $source->refresh();

    expect($source->failure_count)->toBe(2)
        ->and($source->last_failure_reason)->toContain('503')
        ->and(Article::query()->count())->toBe(0);
});

it('trata una fuente sin spider configurado como un fallo aislado', function () {
    $source = Source::factory()->create(['spider_class' => null, 'is_active' => true]);

    dispatch_sync(new ScrapeSourceJob($source->id));

    expect($source->fresh()->last_failure_reason)->toContain('no tiene spider configurado');
});

it('omite las fuentes inactivas', function () {
    $source = sourceWithStub(['is_active' => false]);
    StubSourceScraper::returns($source->slug, [scrapedArticle('https://df.cl/cobre-cinco-dolares')]);

    dispatch_sync(new ScrapeSourceJob($source->id));

    expect(Article::query()->count())->toBe(0)
        ->and($source->fresh()->failure_count)->toBe($source->failure_count);
});

it('limpia el historial de fallos cuando la fuente vuelve a responder', function () {
    $source = sourceWithStub([
        'failure_count' => 3,
        'last_failure_reason' => 'La fuente respondió 403 en las últimas tres corridas.',
    ]);
    StubSourceScraper::returns($source->slug, [scrapedArticle('https://df.cl/cobre-cinco-dolares')]);

    dispatch_sync(new ScrapeSourceJob($source->id));

    $source->refresh();

    expect($source->failure_count)->toBe(0)
        ->and($source->last_failure_reason)->toBeNull();
});

it('respeta el tope de artículos por corrida', function () {
    config(['newsscraper.scraping.max_articles_per_source' => 1]);

    $source = sourceWithStub();
    StubSourceScraper::returns($source->slug, [
        scrapedArticle('https://df.cl/uno'),
        scrapedArticle('https://df.cl/dos', 'Otro titular distinto'),
    ]);

    dispatch_sync(new ScrapeSourceJob($source->id));

    expect(StubSourceScraper::$lastLimit)->toBe(1)
        ->and(Article::query()->count())->toBe(1);
});
