<?php

namespace App\Spiders;

/**
 * Diario Financiero — sección Mercados.
 *
 * Su `robots.txt` dice literalmente "por defecto, permitimos acceso completo a
 * cualquier user-agent" y solo prohíbe `/custom/` y `/qr-inter`, que no se
 * tocan. Se lee únicamente la portada de la sección: los titulares y bajadas que
 * el medio muestra públicamente. Las notas completas están tras muro de pago y
 * no se abren.
 */
class DiarioFinancieroSpider extends HtmlListingSpider
{
    protected function listingUrl(): string
    {
        return 'https://www.df.cl/mercados';
    }

    protected function itemSelector(): string
    {
        return 'article.card';
    }

    /**
     * Solo las secciones periodísticas. El listado mezcla `/df-lab/`,
     * `/df-stream/videos/` y páginas de la tienda, que no son coyuntura.
     */
    protected function articlePathPattern(): string
    {
        return '#^/(mercados|empresas|economia-y-politica|internacional)/[^/]+/[^/]+#i';
    }

    protected function titleSelector(): string
    {
        return '.card__title';
    }

    /**
     * El primer enlace de la tarjeta es la foto y apunta a la nota; el que hay
     * que evitar es `.card__tag`, que lleva a la sección y no al artículo.
     */
    protected function linkSelector(): string
    {
        return '.card__content a:not(.card__tag)';
    }

    protected function excerptSelector(): ?string
    {
        return '.card__description';
    }

    protected function dateSelector(): ?string
    {
        return '.card__date';
    }
}
