<?php

namespace Tests\Fixtures;

use App\Contracts\SourceScraper;
use App\Data\ScrapedArticle;
use App\Exceptions\SourceScrapingException;
use App\Models\Source;

/**
 * Spider de mentira para los tests del pipeline: devuelve lo que se le cargue,
 * sin tocar la red.
 *
 * El resolver lo construye desde el contenedor, así que no puede tener
 * argumentos en el constructor; la configuración va en estado estático y cada
 * test la limpia con reset().
 */
class StubSourceScraper implements SourceScraper
{
    /** @var array<string, list<ScrapedArticle>> Artículos por slug de fuente. */
    public static array $articles = [];

    public static bool $shouldFail = false;

    public static int $lastLimit = 0;

    public function scrape(Source $source, int $limit): array
    {
        if (static::$shouldFail) {
            throw new SourceScrapingException('La fuente respondió 503.');
        }

        static::$lastLimit = $limit;

        return array_slice(static::$articles[$source->slug] ?? [], 0, $limit);
    }

    /**
     * @param  list<ScrapedArticle>  $articles
     */
    public static function returns(string $sourceSlug, array $articles): void
    {
        static::$articles[$sourceSlug] = $articles;
    }

    public static function reset(): void
    {
        static::$articles = [];
        static::$shouldFail = false;
        static::$lastLimit = 0;
    }
}
