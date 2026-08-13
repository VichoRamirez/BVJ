<?php

namespace App\Spiders;

/**
 * BBC News Mundo — Economía.
 *
 * Es la primera fuente real del proyecto, y se eligió por accesibilidad, no por
 * preferencia editorial: de las candidatas chilenas que se probaron (Diario
 * Financiero, Pulso, Emol, BioBioChile, El Mostrador) ninguna expone hoy un RSS
 * utilizable —responden 404, redirigen a la portada o devuelven HTML—, mientras
 * que este feed entrega 33 items y su `robots.txt` no restringe la ruta.
 *
 * Publica en español y cubre coyuntura económica, así que sirve para validar el
 * pipeline de punta a punta mientras se resuelven las fuentes chilenas. No
 * reemplaza a las del MVP: las suma.
 */
class BbcMundoEconomiaSpider extends RssSpider
{
    protected function feedUrl(): string
    {
        return 'https://feeds.bbci.co.uk/mundo/economia/rss.xml';
    }
}
