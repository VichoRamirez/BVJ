<?php

namespace App\Spiders;

use App\Contracts\SourceScraper;
use App\Data\ScrapedArticle;
use App\Exceptions\SourceScrapingException;
use App\Models\Source;
use App\Services\Scraping\SafeHttpFetcher;
use DateTimeImmutable;
use Illuminate\Support\Str;
use LibXMLError;
use SimpleXMLElement;
use Throwable;

/**
 * Base para las fuentes que publican RSS.
 *
 * Un spider RSS **no abre la nota**: se queda con lo que el propio medio expone
 * en el feed (titular, enlace, fecha y bajada). Eso resuelve de raíz el
 * requisito de no almacenar ni republicar el cuerpo con copyright (CLAUDE.md
 * §4): lo que se guarda es lo que el medio publica para ser sindicado, y lo que
 * se muestra es el resumen generado más el enlace al original.
 *
 * También es la razón de preferir RSS sobre HTML para la primera fuente: no
 * depende de selectores que se rompen con cada rediseño.
 */
abstract class RssSpider implements SourceScraper
{
    public function __construct(protected readonly SafeHttpFetcher $fetcher = new SafeHttpFetcher) {}

    /**
     * URL del feed. Debe estar en `config('newsscraper.scraping.allowed_hosts')`.
     */
    abstract protected function feedUrl(): string;

    /**
     * @return list<ScrapedArticle>
     */
    public function scrape(Source $source, int $limit): array
    {
        $items = $this->channelItems($this->fetcher->get($this->feedUrl()));
        $articles = [];

        foreach ($items as $item) {
            if (count($articles) >= $limit) {
                break;
            }

            $article = $this->toArticle($item);

            if ($article !== null) {
                $articles[] = $article;
            }
        }

        return $articles;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function channelItems(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            // LIBXML_NONET además de no cargar entidades externas: el feed es
            // contenido de terceros y no puede hacer que el parser salga a la red.
            $feed = new SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable $exception) {
            $detail = implode('; ', array_map(
                static fn (LibXMLError $error): string => trim($error->message),
                libxml_get_errors()
            ));

            throw new SourceScrapingException('El feed no es XML válido. '.$detail, previous: $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $items = $feed->channel->item ?? $feed->item ?? null;

        if ($items === null) {
            throw new SourceScrapingException('El feed no contiene items.');
        }

        return iterator_to_array($items, false);
    }

    private function toArticle(SimpleXMLElement $item): ?ScrapedArticle
    {
        $url = trim((string) ($item->link ?? ''));
        $title = $this->clean((string) ($item->title ?? ''));

        if ($url === '' || $title === '') {
            return null;
        }

        $excerpt = $this->clean((string) ($item->description ?? ''));

        // Sin bajada no hay nada que analizar: el spider no abre la nota.
        if ($excerpt === '') {
            return null;
        }

        try {
            return new ScrapedArticle(
                url: $url,
                title: Str::limit($title, 480, ''),
                author: $this->author($item),
                publishedAt: $this->publishedAt($item),
                excerpt: $this->truncate($excerpt, (int) config('newsscraper.scraping.max_excerpt_chars', 600)),
                // El feed es todo lo que hay para analizar, y es suficiente: el
                // titular más la bajada bastan para resumir y clasificar.
                content: $this->truncate($excerpt, (int) config('newsscraper.scraping.max_content_chars', 4000)),
            );
        } catch (Throwable) {
            // Un item malformado no puede tumbar el feed completo.
            return null;
        }
    }

    private function author(SimpleXMLElement $item): ?string
    {
        $creator = $item->children('http://purl.org/dc/elements/1.1/')->creator ?? null;
        $author = $this->clean((string) ($creator ?? $item->author ?? ''));

        return $author === '' ? null : Str::limit($author, 200, '');
    }

    private function publishedAt(SimpleXMLElement $item): ?DateTimeImmutable
    {
        $raw = trim((string) ($item->pubDate ?? ''));

        if ($raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * El feed puede traer HTML en el título o la bajada; acá se convierte a
     * texto plano antes de que llegue a la base o al prompt.
     */
    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function truncate(string $value, int $limit): ?string
    {
        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
