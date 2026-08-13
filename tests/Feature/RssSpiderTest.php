<?php

use App\Exceptions\DisallowedScrapingTargetException;
use App\Exceptions\SourceScrapingException;
use App\Models\Source;
use App\Services\Scraping\SafeHttpFetcher;
use App\Spiders\BbcMundoEconomiaSpider;
use Illuminate\Support\Facades\Http;

/*
 * Las arañas contra fixtures locales. Ningún test sale a la red: el feed y el
 * robots.txt van falseados y `Http::preventStrayRequests()` (tests/Pest.php)
 * avisa si alguno se escapa.
 */

beforeEach(function (): void {
    // Sin DNS en los tests. La lógica de rangos privados se prueba aparte.
    config(['newsscraper.scraping.verify_public_address' => false]);
    config(['newsscraper.scraping.delay_seconds' => 0]);
});

function fakeFeed(string $body, int $status = 200, string $robots = "User-agent: *\nDisallow: /cgi-bin\n"): void
{
    Http::fake([
        'feeds.bbci.co.uk/robots.txt' => Http::response($robots),
        'feeds.bbci.co.uk/mundo/economia/rss.xml' => Http::response($body, $status),
    ]);
}

function bbcFixture(): string
{
    return file_get_contents(base_path('tests/Fixtures/bbc-mundo-economia.xml'));
}

function scrapeBbc(int $limit = 25): array
{
    return app(BbcMundoEconomiaSpider::class)->scrape(
        Source::factory()->create(['slug' => 'bbc-mundo-economia']),
        $limit
    );
}

it('extrae los artículos del feed real', function () {
    fakeFeed(bbcFixture());

    $articles = scrapeBbc();

    expect($articles)->toHaveCount(3)
        ->and($articles[0]->url)->toStartWith('https://www.bbc.com/mundo/articles/')
        ->and($articles[0]->title)->not->toBeEmpty()
        ->and($articles[0]->excerpt)->not->toBeEmpty()
        ->and($articles[0]->publishedAt)->toBeInstanceOf(DateTimeImmutable::class);
});

it('respeta el tope de artículos por corrida', function () {
    fakeFeed(bbcFixture());

    expect(scrapeBbc(limit: 2))->toHaveCount(2);
});

it('convierte el HTML del feed en texto plano', function () {
    fakeFeed(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
            <item>
                <title>El &amp;quot;cobre&amp;quot; sube</title>
                <description><![CDATA[<p>Sube <b>2%</b>&nbsp;en la sesión.</p>]]></description>
                <link>https://www.bbc.com/mundo/articles/abc</link>
                <pubDate>Wed, 12 Aug 2026 11:57:01 GMT</pubDate>
            </item>
        </channel></rss>
        XML);

    $article = scrapeBbc()[0];

    expect($article->title)->toBe('El "cobre" sube')
        ->and($article->excerpt)->toBe('Sube 2% en la sesión.');
});

it('recorta el texto guardado al máximo configurado', function () {
    config(['newsscraper.scraping.max_excerpt_chars' => 20]);
    config(['newsscraper.scraping.max_content_chars' => 30]);

    fakeFeed(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
            <item>
                <title>Titular</title>
                <description>Una bajada bastante larga que supera con comodidad los límites configurados.</description>
                <link>https://www.bbc.com/mundo/articles/abc</link>
            </item>
        </channel></rss>
        XML);

    $article = scrapeBbc()[0];

    // No se republica el cuerpo completo: se guarda lo mínimo para analizar.
    expect(mb_strlen($article->excerpt))->toBeLessThanOrEqual(20)
        ->and(mb_strlen($article->content))->toBeLessThanOrEqual(30)
        ->and(mb_strlen($article->content))->toBeGreaterThan(mb_strlen($article->excerpt));
});

it('descarta un item malformado sin perder el resto del feed', function () {
    fakeFeed(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
            <item><title>Sin enlace</title><description>x</description></item>
            <item><title></title><description>x</description><link>https://www.bbc.com/mundo/articles/sin-titulo</link></item>
            <item><title>Sin bajada</title><link>https://www.bbc.com/mundo/articles/sin-bajada</link></item>
            <item><title>Válido</title><description>Bajada con texto.</description><link>https://www.bbc.com/mundo/articles/ok</link></item>
        </channel></rss>
        XML);

    $articles = scrapeBbc();

    // Sin bajada no hay nada que analizar —el spider no abre la nota—, así que
    // ese item se descarta igual que los que vienen sin enlace o sin titular.
    expect($articles)->toHaveCount(1)
        ->and($articles[0]->title)->toBe('Válido');
});

it('falla con un mensaje claro si el feed no es XML', function () {
    fakeFeed('<!doctype html><html><body>portada</body></html>');

    expect(fn () => scrapeBbc())->toThrow(SourceScrapingException::class, 'no es XML válido');
});

it('trata un error HTTP del feed como fallo de la fuente', function () {
    fakeFeed('', status: 503);

    expect(fn () => scrapeBbc())->toThrow(SourceScrapingException::class, '503');
});

it('no sale a un host fuera de la allowlist', function () {
    config(['newsscraper.scraping.allowed_hosts' => ['otro-sitio.cl']]);
    fakeFeed(bbcFixture());

    expect(fn () => scrapeBbc())
        ->toThrow(DisallowedScrapingTargetException::class, 'no está en la allowlist');
});

it('obedece a robots.txt', function () {
    fakeFeed(bbcFixture(), robots: "User-agent: *\nDisallow: /mundo/\n");

    expect(fn () => scrapeBbc())
        ->toThrow(DisallowedScrapingTargetException::class, 'robots.txt prohíbe');
});

it('deja pasar la ruta cuando robots.txt la permite explícitamente', function () {
    // Allow más específico que el Disallow: gana Allow.
    fakeFeed(bbcFixture(), robots: "User-agent: *\nDisallow: /mundo/\nAllow: /mundo/economia/\n");

    expect(scrapeBbc())->toHaveCount(3);
});

it('rechaza una URL que no sea HTTPS', function () {
    expect(fn () => app(SafeHttpFetcher::class)->get('http://feeds.bbci.co.uk/mundo/economia/rss.xml'))
        ->toThrow(DisallowedScrapingTargetException::class, 'HTTPS');
});

it('revalida la allowlist en cada redirección', function () {
    Http::fake([
        'feeds.bbci.co.uk/robots.txt' => Http::response("User-agent: *\n"),
        'feeds.bbci.co.uk/mundo/economia/rss.xml' => Http::response('', 302, ['Location' => 'https://evil.example/feed.xml']),
    ]);

    // Una fuente comprometida no puede empujar al bot hacia otro dominio.
    expect(fn () => scrapeBbc())
        ->toThrow(DisallowedScrapingTargetException::class, 'evil.example');
});

it('corta una respuesta más grande que el máximo permitido', function () {
    config(['newsscraper.scraping.max_response_bytes' => 100]);
    fakeFeed(bbcFixture());

    expect(fn () => scrapeBbc())->toThrow(SourceScrapingException::class, 'tamaño máximo');
});

it('rechaza los hosts que resuelven a direcciones privadas', function (array $addresses, bool $expected) {
    expect(app(SafeHttpFetcher::class)->isPublicAddress($addresses))->toBe($expected);
})->with([
    'loopback' => [['127.0.0.1'], false],
    'red interna' => [['10.0.0.5'], false],
    'link-local' => [['169.254.169.254'], false],
    'sin resolución' => [[[]][0], false],
    'una privada entre públicas' => [['151.101.0.81', '192.168.1.10'], false],
    'pública' => [['151.101.0.81'], true],
]);
