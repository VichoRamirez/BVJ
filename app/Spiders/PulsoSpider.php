<?php

namespace App\Spiders;

/**
 * Pulso · La Tercera.
 *
 * Su `robots.txt` permite `/` y solo prohíbe `/pf/api/v3/*` y `/search/`, que no
 * se tocan. El listado no publica la fecha de cada nota: los artículos llegan
 * sin `publishedAt` y `ScrapeSourceJob` usa la hora de recolección como
 * aproximación.
 */
class PulsoSpider extends HtmlListingSpider
{
    protected function listingUrl(): string
    {
        return 'https://www.latercera.com/canal/pulso/';
    }

    protected function itemSelector(): string
    {
        return '.story-card';
    }

    /**
     * Solo `/pulso/noticia/`. El listado incluye `/publirreportajes/` y
     * `/branded/`, que son contenido pagado: no entran al briefing.
     */
    protected function articlePathPattern(): string
    {
        return '#^/pulso/noticia/#i';
    }

    protected function titleSelector(): string
    {
        return '.story-card__headline';
    }

    protected function linkSelector(): string
    {
        return '.story-card__headline a[href]';
    }

    protected function excerptSelector(): ?string
    {
        return '.story-card__description';
    }
}
