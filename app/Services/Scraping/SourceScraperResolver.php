<?php

namespace App\Services\Scraping;

use App\Contracts\SourceScraper;
use App\Exceptions\UnresolvableSourceScraperException;
use App\Models\Source;
use Throwable;

/**
 * Traduce la columna `sources.spider_class` en una instancia del contrato.
 *
 * Que falle aquí es el caso normal mientras no existan los spiders reales: la
 * fuente no tiene spider configurado. ScrapeSourceJob lo trata como un fallo de
 * esa fuente —incrementa `failure_count`, registra el motivo— y sigue con las
 * demás, en vez de voltear el lote (CLAUDE.md §4).
 */
final class SourceScraperResolver
{
    /**
     * @param  string|null  $override  Clase forzada desde `news:scrape --spider=`.
     */
    public function resolve(Source $source, ?string $override = null): SourceScraper
    {
        $class = trim((string) ($override ?? $source->spider_class ?? ''));

        if ($class === '') {
            throw new UnresolvableSourceScraperException(
                "La fuente {$source->name} no tiene spider configurado (columna spider_class)."
            );
        }

        // `spider_class` es un dato de la base y `--spider=` viene de la línea de
        // comandos: ninguno de los dos puede terminar instanciando una clase
        // arbitraria del proyecto. Solo entra lo que está en la allowlist.
        if (! in_array($class, (array) config('newsscraper.scraping.spiders', []), true)) {
            throw new UnresolvableSourceScraperException(
                "El spider {$class} no está en la allowlist de config('newsscraper.scraping.spiders')."
            );
        }

        if (! class_exists($class)) {
            throw new UnresolvableSourceScraperException(
                "El spider {$class} de la fuente {$source->name} no existe."
            );
        }

        try {
            $scraper = app($class);
        } catch (Throwable $exception) {
            throw new UnresolvableSourceScraperException(
                "No fue posible construir el spider {$class}: {$exception->getMessage()}",
                previous: $exception
            );
        }

        if (! $scraper instanceof SourceScraper) {
            throw new UnresolvableSourceScraperException(
                "El spider {$class} no implementa ".SourceScraper::class.'.'
            );
        }

        return $scraper;
    }
}
