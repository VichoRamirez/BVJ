<?php

use App\Models\Source;
use App\Spiders\DiarioFinancieroSpider;
use App\Spiders\PulsoSpider;
use Illuminate\Support\Facades\Http;

/*
 * Arañas HTML contra fixtures recortadas de las portadas reales de cada medio.
 * Ningún test sale a la red.
 *
 * Estas fixtures son además el detector de quiebre: cuando un medio cambie su
 * maquetado, estos tests fallan antes de que el pipeline empiece a recolectar
 * cero artículos en silencio.
 */

beforeEach(function (): void {
    config(['newsscraper.scraping.verify_public_address' => false]);
    config(['newsscraper.scraping.delay_seconds' => 0]);
});

function fakeListing(string $host, string $fixture): void
{
    Http::fake([
        $host.'/robots.txt' => Http::response("User-agent: *\nDisallow: /custom/\n"),
        $host.'/*' => Http::response(file_get_contents(base_path("tests/Fixtures/{$fixture}"))),
    ]);
}

it('extrae las notas del listado de Diario Financiero', function () {
    fakeListing('www.df.cl', 'df-mercados.html');

    $articles = app(DiarioFinancieroSpider::class)->scrape(Source::factory()->create(), 25);

    expect($articles)->not->toBeEmpty();

    foreach ($articles as $article) {
        expect($article->url)->toStartWith('https://www.df.cl/')
            ->and($article->title)->not->toBeEmpty()
            ->and($article->excerpt)->not->toBeEmpty();
    }
});

it('descarta del listado de Diario Financiero lo que no es periodismo de coyuntura', function () {
    fakeListing('www.df.cl', 'df-mercados.html');

    $paths = collect(app(DiarioFinancieroSpider::class)->scrape(Source::factory()->create(), 25))
        ->map(fn ($article): string => parse_url($article->url, PHP_URL_PATH));

    // El listado mezcla /df-lab/ y /df-stream/videos/ con las secciones de noticias.
    expect($paths)->not->toBeEmpty()
        ->and($paths->filter(fn (string $path): bool => str_starts_with($path, '/df-lab/')))->toBeEmpty()
        ->and($paths->filter(fn (string $path): bool => str_starts_with($path, '/df-stream/')))->toBeEmpty();
});

it('extrae las notas del listado de Pulso', function () {
    fakeListing('www.latercera.com', 'pulso-listado.html');

    $articles = app(PulsoSpider::class)->scrape(Source::factory()->create(), 25);

    expect($articles)->not->toBeEmpty();

    foreach ($articles as $article) {
        expect($article->url)->toContain('/pulso/noticia/')
            ->and($article->excerpt)->not->toBeEmpty();
    }
});

it('no publica publirreportajes ni contenido branded como si fueran noticias', function () {
    fakeListing('www.latercera.com', 'pulso-listado.html');

    $urls = collect(app(PulsoSpider::class)->scrape(Source::factory()->create(), 25))
        ->map(fn ($article): string => $article->url);

    // Es contenido pagado: resumirlo con IA y mostrarlo junto a las noticias
    // del briefing engañaría al lector.
    expect($urls)->not->toBeEmpty()
        ->and($urls->filter(fn (string $url): bool => str_contains($url, '/publirreportajes/')))->toBeEmpty()
        ->and($urls->filter(fn (string $url): bool => str_contains($url, '/branded/')))->toBeEmpty();
});

it('convierte los enlaces relativos del listado en URLs absolutas', function () {
    fakeListing('www.latercera.com', 'pulso-listado.html');

    foreach (app(PulsoSpider::class)->scrape(Source::factory()->create(), 25) as $article) {
        expect($article->url)->toStartWith('https://www.latercera.com/');
    }
});

it('respeta el tope de artículos por corrida', function () {
    fakeListing('www.df.cl', 'df-mercados.html');

    expect(app(DiarioFinancieroSpider::class)->scrape(Source::factory()->create(), 1))->toHaveCount(1);
});

it('descarta las tarjetas sin bajada en vez de dejar filas inanalizables', function () {
    Http::fake([
        'www.df.cl/robots.txt' => Http::response("User-agent: *\n"),
        'www.df.cl/*' => Http::response(<<<'HTML'
            <!doctype html><html><body>
            <article class="card"><div class="card__content">
                <a href="/mercados/banca/con-bajada"><h3 class="card__title">Con bajada</h3>
                <p class="card__description">Tiene texto para analizar.</p></a>
            </div></article>
            <article class="card"><div class="card__content">
                <a href="/mercados/banca/sin-bajada"><h3 class="card__title">Sin bajada</h3></a>
            </div></article>
            </body></html>
            HTML),
    ]);

    $articles = app(DiarioFinancieroSpider::class)->scrape(Source::factory()->create(), 25);

    // El análisis marcaría la segunda como fallida por no tener texto: mejor no
    // guardarla.
    expect($articles)->toHaveCount(1)
        ->and($articles[0]->title)->toBe('Con bajada');
});

it('no repite un artículo que aparece en dos tarjetas del listado', function () {
    $card = <<<'HTML'
        <div class="story-card">
            <h2 class="story-card__headline"><a href="/pulso/noticia/repetida/">Nota repetida</a></h2>
            <span class="story-card__description"><a href="/pulso/noticia/repetida/">Bajada de la nota.</a></span>
        </div>
        HTML;

    Http::fake([
        'www.latercera.com/robots.txt' => Http::response("User-agent: *\n"),
        'www.latercera.com/*' => Http::response("<!doctype html><html><body>{$card}{$card}</body></html>"),
    ]);

    expect(app(PulsoSpider::class)->scrape(Source::factory()->create(), 25))->toHaveCount(1);
});

it('lee la fecha del listado en el formato chileno d/m/Y', function () {
    Http::fake([
        'www.df.cl/robots.txt' => Http::response("User-agent: *\n"),
        'www.df.cl/*' => Http::response(<<<'HTML'
            <!doctype html><html><body>
            <article class="card">
                <div class="card__content">
                    <a class="card__tag" href="/mercados/pensiones">Pensiones</a>
                    <span class="card__date"></span>
                    <span class="card__date">12/08/2026</span>
                    <a href="/mercados/pensiones/nota-con-fecha">
                        <h3 class="card__title">Nota con fecha</h3>
                        <p class="card__description">Bajada de la nota.</p>
                    </a>
                </div>
            </article>
            </body></html>
            HTML),
    ]);

    $article = app(DiarioFinancieroSpider::class)->scrape(Source::factory()->create(), 25)[0];

    // El primer `.card__date` viene vacío: hay que tomar el primero con texto.
    expect($article->publishedAt->format('Y-m-d'))->toBe('2026-08-12')
        ->and($article->url)->toBe('https://www.df.cl/mercados/pensiones/nota-con-fecha');
});
