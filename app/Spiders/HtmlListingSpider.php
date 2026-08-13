<?php

namespace App\Spiders;

use App\Contracts\SourceScraper;
use App\Data\ScrapedArticle;
use App\Models\Source;
use App\Services\Scraping\SafeHttpFetcher;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Base para las fuentes que no publican RSS y hay que leer del listado HTML.
 *
 * Del listado salen titular, enlace y bajada. De la nota se leen **solo los
 * metadatos** —fecha de publicación y autor— porque los listados chilenos casi
 * no los exponen; el cuerpo con copyright no se guarda nunca (CLAUDE.md §4) y la
 * bajada sigue siendo la del listado, que es lo que el medio muestra en abierto.
 *
 * Costo: un request por el listado más uno por artículo, ya recortado al tope de
 * la corrida. Se puede apagar con `NEWS_SCRAPE_FETCH_METADATA=false`, y en ese
 * caso las fechas vuelven a caer a la hora del scrape.
 *
 * Cada fuente se resuelve declarando selectores, no reescribiendo lógica: eso
 * mantiene en un solo lugar el saneamiento del texto, la resolución de URLs
 * relativas y el descarte de items rotos. Cuando un medio cambie su maquetado,
 * lo que hay que tocar son las constantes de su subclase.
 */
abstract class HtmlListingSpider implements SourceScraper
{
    public function __construct(protected readonly SafeHttpFetcher $fetcher = new SafeHttpFetcher) {}

    /**
     * Portada de la sección a leer.
     */
    abstract protected function listingUrl(): string;

    /**
     * Selector CSS de cada tarjeta del listado.
     */
    abstract protected function itemSelector(): string;

    /**
     * Selector del titular, relativo a la tarjeta.
     */
    abstract protected function titleSelector(): string;

    /**
     * Selector del enlace a la nota, relativo a la tarjeta.
     */
    abstract protected function linkSelector(): string;

    protected function excerptSelector(): ?string
    {
        return null;
    }

    protected function dateSelector(): ?string
    {
        return null;
    }

    /**
     * Expresión regular que la ruta del enlace debe cumplir para aceptarse.
     *
     * No es un detalle cosmético: los listados mezclan periodismo con
     * publirreportajes, contenido *branded* y videos. Publicar publicidad pagada
     * dentro de un briefing financiero, resumida por una IA y presentada igual
     * que una noticia, sería engañar al lector. Cada fuente declara acá qué
     * rutas son notas de verdad.
     */
    abstract protected function articlePathPattern(): string;

    /**
     * Formato de fecha del listado. Se sobrescribe cuando el medio usa uno que
     * `DateTimeImmutable` no interpreta solo (en Chile, `d/m/Y`).
     */
    protected function parseDate(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // El `!` importa: sin él, `createFromFormat` rellena los campos que el
        // formato no trae con la hora *actual*, así que "13/08/2026" quedaba
        // como la hora del scrape y no como el comienzo del día.
        foreach (['!d/m/Y H:i', '!d/m/Y', '!d-m-Y H:i', '!d-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);

            if ($date !== false) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<ScrapedArticle>
     */
    public function scrape(Source $source, int $limit): array
    {
        $listing = $this->listingUrl();
        $crawler = new Crawler($this->fetcher->get($listing), $listing);
        $articles = [];
        $seen = [];

        foreach ($crawler->filter($this->itemSelector()) as $node) {
            if (count($articles) >= $limit) {
                break;
            }

            $article = $this->toArticle(new Crawler($node, $listing));

            // El mismo artículo suele aparecer en varias tarjetas del listado
            // (destacado y sección). Se descarta acá para no llevar duplicados
            // a la base, aunque `url_hash` igual los colapsaría.
            if ($article === null || in_array($article->url, $seen, true)) {
                continue;
            }

            $seen[] = $article->url;
            $articles[] = $article;
        }

        // La visita a cada nota va después de aplicar el tope: enriquecer 100
        // tarjetas para quedarse con 25 sería gastar requests contra el medio.
        return config('newsscraper.scraping.fetch_article_metadata', true)
            ? array_map($this->enrich(...), $articles)
            : $articles;
    }

    /**
     * Completa fecha y autor abriendo la nota.
     *
     * Los listados chilenos casi no publican fecha —medido el 2026-08-13, Diario
     * Financiero la trae en 12 de 103 tarjetas y Pulso en 0 de 77— y sin ella
     * `ScrapeSourceJob` cae a la hora del scrape, con lo que todos los artículos
     * de una corrida quedan con el mismo instante. Eso rompe la ventana de
     * agrupación y el corte del briefing, que se miden contra `published_at`.
     *
     * La nota sí la trae, en JSON-LD o en `<meta property="article:published_time">`.
     * Se leen solo esos metadatos: no se guarda el cuerpo (CLAUDE.md §4).
     *
     * Nunca descarta: si la nota no responde, o cambia el marcado, o es un
     * paywall, se devuelve el artículo tal como venía del listado. Perder la
     * hora exacta es aceptable; perder el artículo, no.
     */
    private function enrich(ScrapedArticle $article): ScrapedArticle
    {
        try {
            $page = new Crawler($this->fetcher->get($article->url), $article->url);
        } catch (Throwable) {
            return $article;
        }

        $publishedAt = $article->publishedAt ?? $this->publishedAtFromPage($page);
        $author = $article->author ?? $this->authorFromPage($page);

        if ($publishedAt === $article->publishedAt && $author === $article->author) {
            return $article;
        }

        try {
            return new ScrapedArticle(
                url: $article->url,
                title: $article->title,
                author: $author,
                publishedAt: $publishedAt,
                excerpt: $article->excerpt,
                content: $article->content,
            );
        } catch (Throwable) {
            return $article;
        }
    }

    private function publishedAtFromPage(Crawler $page): ?DateTimeImmutable
    {
        $raw = $this->metaContent($page, 'meta[property="article:published_time"]')
            ?? $this->metaContent($page, 'meta[itemprop="datePublished"]')
            ?? $this->fromLinkedData($page, 'datePublished');

        return $raw === null ? null : $this->parseDate($raw);
    }

    private function authorFromPage(Crawler $page): ?string
    {
        $author = $this->metaContent($page, 'meta[name="author"]')
            ?? $this->fromLinkedData($page, 'name', within: 'author');

        $author = trim(preg_replace('/\s+/u', ' ', (string) $author) ?? '');

        return $author === '' ? null : Str::limit($author, 255, '');
    }

    private function metaContent(Crawler $page, string $selector): ?string
    {
        $node = $page->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $content = trim((string) $node->first()->attr('content'));

        return $content === '' ? null : $content;
    }

    /**
     * Busca una clave dentro de los bloques JSON-LD de la nota.
     *
     * Se recorre el árbol decodificado en vez de usar una expresión regular: los
     * medios anidan el artículo dentro de `@graph`, y `author` puede ser un
     * objeto, una lista de objetos o una cadena suelta.
     */
    private function fromLinkedData(Crawler $page, string $key, ?string $within = null): ?string
    {
        foreach ($page->filter('script[type="application/ld+json"]') as $node) {
            $decoded = json_decode((string) $node->textContent, true);

            if (! is_array($decoded)) {
                continue;
            }

            $found = $this->searchLinkedData($decoded, $key, $within);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function searchLinkedData(array $data, string $key, ?string $within): ?string
    {
        if ($within === null && isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
            return trim($data[$key]);
        }

        foreach ($data as $childKey => $value) {
            if (! is_array($value)) {
                continue;
            }

            // Al entrar en la rama pedida (`author`), se deja de exigirla hacia
            // abajo: la clave buscada vive dentro de ella.
            $found = $this->searchLinkedData($value, $key, $within === $childKey ? null : $within);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function toArticle(Crawler $card): ?ScrapedArticle
    {
        $url = $this->absoluteUrl($this->attribute($card, $this->linkSelector(), 'href'));
        $title = $this->text($card, $this->titleSelector());

        if ($url === null || $title === '' || ! $this->isArticleUrl($url)) {
            return null;
        }

        $excerpt = $this->excerptSelector() === null ? '' : $this->text($card, $this->excerptSelector());

        // Sin bajada no hay nada que analizar —y no se abre la nota para
        // buscarla—, así que se descarta en vez de dejar una fila que el
        // análisis va a marcar como fallida.
        if ($excerpt === '') {
            return null;
        }

        $rawDate = $this->dateSelector() === null ? '' : $this->text($card, $this->dateSelector());

        try {
            return new ScrapedArticle(
                url: $url,
                title: Str::limit($title, 480, ''),
                author: null,
                publishedAt: $rawDate === '' ? null : $this->parseDate($rawDate),
                excerpt: $this->truncate($excerpt, (int) config('newsscraper.scraping.max_excerpt_chars', 600)),
                content: $this->truncate($excerpt, (int) config('newsscraper.scraping.max_content_chars', 4000)),
            );
        } catch (Throwable) {
            // Una tarjeta rota no puede tumbar el listado completo.
            return null;
        }
    }

    private function isArticleUrl(string $url): bool
    {
        return preg_match($this->articlePathPattern(), (string) (parse_url($url)['path'] ?? '')) === 1;
    }

    /**
     * Primer nodo del selector con texto. Los listados repiten el mismo selector
     * en nodos vacíos (etiquetas de maquetado), y quedarse con `first()` a secas
     * devolvería cadena vacía aunque el dato exista más abajo en la tarjeta.
     */
    private function text(Crawler $card, string $selector): string
    {
        foreach ($card->filter($selector) as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', (new Crawler($node))->text('')) ?? '');

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function attribute(Crawler $card, string $selector, string $attribute): ?string
    {
        $node = $card->filter($selector);

        return $node->count() === 0 ? null : $node->first()->attr($attribute);
    }

    /**
     * Los listados enlazan con rutas relativas. La URL absoluta se arma contra
     * el host del listado, que ya pasó por la allowlist.
     */
    private function absoluteUrl(?string $href): ?string
    {
        $href = trim((string) $href);

        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $parts = parse_url($this->listingUrl());

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').'/'.ltrim($href, '/');
    }

    private function truncate(string $value, int $limit): ?string
    {
        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
