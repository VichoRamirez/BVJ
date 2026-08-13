<?php

namespace App\Spiders;

/**
 * BBC News — Business (https://www.bbc.com/business).
 *
 * Se usa el RSS de la sección y no el HTML: trae `pubDate` real por item, que es
 * justo lo que los listados chilenos no exponen.
 *
 * **Reemplaza al feed de BBC Mundo Economía, que no era de economía.** Medido el
 * 2026-08-13: `feeds.bbci.co.uk/mundo/economia/rss.xml` devuelve el feed general
 * de BBC Mundo —su `<title>` es "BBC Mundo" y su `<link>` apunta a
 * `bbc.com/mundo`— y entregaba terremotos, eclipses y perfiles biográficos. Para
 * un briefing financiero eso es ruido que ensucia categorías y portada.
 *
 * **Contrapartida a tener presente:** publica en inglés y con foco en Reino
 * Unido. El análisis se pide en español, así que el resumen sale traducido, pero
 * la cobertura no es chilena. Sirve como fuente de contraste y para que el
 * pipeline nunca se quede sin material; las fuentes del MVP son las locales.
 */
class BbcBusinessSpider extends RssSpider
{
    protected function feedUrl(): string
    {
        return 'https://feeds.bbci.co.uk/news/business/rss.xml';
    }
}
