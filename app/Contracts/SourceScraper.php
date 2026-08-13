<?php

namespace App\Contracts;

use App\Data\ScrapedArticle;
use App\Models\Source;

/**
 * Un spider por fuente (PLAN.md §3.1).
 *
 * La decisión de con qué librería se hace la extracción sigue abierta —Roach no
 * entra en Laravel 13—, pero el resto del pipeline no necesita saberlo: habla
 * con este contrato. Cambiar de librería es reescribir las implementaciones,
 * no los jobs.
 *
 * Quien implemente esto es responsable de respetar `robots.txt`, el retardo
 * entre requests y el User-Agent de config('newsscraper.scraping'), y de no
 * eludir paywalls.
 */
interface SourceScraper
{
    /**
     * @param  int  $limit  Máximo de artículos a devolver en esta corrida.
     * @return list<ScrapedArticle>
     */
    public function scrape(Source $source, int $limit): array;
}
