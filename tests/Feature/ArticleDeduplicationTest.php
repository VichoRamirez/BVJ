<?php

use App\Exceptions\InvalidArticleUrlException;
use App\Models\Article;
use App\Models\Source;
use App\Support\CanonicalUrl;

/*
 * Idempotencia del pipeline: reprocesar el mismo artículo no puede duplicar
 * filas (CLAUDE.md §4, PLAN.md §5).
 */

it('normaliza URLs equivalentes al mismo hash', function (string $variant) {
    $canonical = 'https://www.df.cl/mercados/cobre-maximos';

    expect(CanonicalUrl::hash($variant))->toBe(CanonicalUrl::hash($canonical));
})->with([
    'barra final' => 'https://www.df.cl/mercados/cobre-maximos/',
    'sin www' => 'https://df.cl/mercados/cobre-maximos',
    'host en mayúsculas' => 'https://WWW.DF.CL/mercados/cobre-maximos',
    'fragmento' => 'https://www.df.cl/mercados/cobre-maximos#comentarios',
    'utm' => 'https://www.df.cl/mercados/cobre-maximos?utm_source=twitter&utm_medium=social',
    'gclid' => 'https://www.df.cl/mercados/cobre-maximos?gclid=abc123',
    'puerto por defecto' => 'https://www.df.cl:443/mercados/cobre-maximos',
]);

it('distingue artículos realmente distintos', function () {
    expect(CanonicalUrl::hash('https://www.df.cl/a'))
        ->not->toBe(CanonicalUrl::hash('https://www.df.cl/b'));
});

it('conserva los parámetros que sí identifican al artículo', function () {
    expect(CanonicalUrl::normalize('https://www.df.cl/nota?id=42&utm_source=x'))
        ->toBe('https://df.cl/nota?id=42');
});

it('no duplica filas al reprocesar el mismo artículo', function () {
    $source = Source::factory()->create();
    $url = 'https://www.df.cl/mercados/cobre-maximos';

    foreach (['Primer titular', 'Titular corregido'] as $title) {
        Article::updateOrCreate(
            ['url_hash' => CanonicalUrl::hash($url)],
            ['source_id' => $source->id, 'url' => $url, 'title' => $title],
        );
    }

    expect(Article::count())->toBe(1)
        ->and(Article::first()->title)->toBe('Titular corregido');
});

it('rechaza esquemas no HTTP y URLs sin host antes de persistir', function (string $url) {
    expect(fn () => Article::factory()->create(['url' => $url]))
        ->toThrow(InvalidArticleUrlException::class);
})->with([
    'javascript' => 'javascript:alert(1)',
    'missing host' => 'https:///noticia',
]);

it('acepta una URL HTTP válida en el modelo', function () {
    $article = Article::factory()->create(['url' => 'http://example.com/noticia']);

    expect($article->url_hash)->toBe(CanonicalUrl::hash($article->url));
});

it('rechaza credenciales embebidas en la URL del artículo', function () {
    foreach (['https://user@example.com/noticia', 'https://:secret@example.com/noticia', 'https://user:secret@example.com/noticia'] as $url) {
        expect(fn () => Article::factory()->create(['url' => $url]))
            ->toThrow(InvalidArticleUrlException::class);
    }
});

it('recalcula el hash aunque le pasen uno equivocado', function () {
    $article = Article::factory()->create(['url' => 'https://www.df.cl/nota']);

    $article->url_hash = 'un-hash-inventado';
    $article->save();

    expect($article->fresh()->url_hash)
        ->toBe(CanonicalUrl::hash('https://www.df.cl/nota'));
});
