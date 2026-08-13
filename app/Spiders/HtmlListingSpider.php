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
 * Solo se lee la **portada de la sección**, nunca la nota: de ahí salen titular,
 * enlace, bajada y fecha, que es lo mismo que el medio muestra públicamente en
 * su home. No se abre el artículo, así que no se topa con paywalls ni se guarda
 * el cuerpo con copyright (CLAUDE.md §4). Un solo request por corrida.
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

        foreach (['d/m/Y H:i', 'd/m/Y', 'd-m-Y H:i', 'd-m-Y'] as $format) {
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

        return $articles;
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
