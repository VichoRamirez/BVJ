<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NewsScraper
|--------------------------------------------------------------------------
|
| Parámetros del agente de coyuntura. Ninguna otra parte de la aplicación
| llama a env() directamente: todo se lee desde aquí con config().
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Capa de IA
    |--------------------------------------------------------------------------
    |
    | El driver se resuelve en AppServiceProvider contra el contrato
    | App\Contracts\NewsAnalyzer. Agregar un GeminiAnalyzer es sumar una clase
    | y cambiar NEWS_AI_DRIVER, sin tocar el pipeline (ver CLAUDE.md §3).
    |
    */

    'ai' => [
        'driver' => env('NEWS_AI_DRIVER', 'ollama'),

        'ollama' => [
            'url' => env('OLLAMA_API_URL', 'https://ollama.com/api/chat'),
            'key' => env('OLLAMA_API_KEY'),
            'model' => env('OLLAMA_MODEL', 'gpt-oss:20b-cloud'),
            'timeout' => (int) env('OLLAMA_TIMEOUT', 60),
            'retries' => (int) env('OLLAMA_RETRIES', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Relevancia
    |--------------------------------------------------------------------------
    |
    | relevance_score = RelevanceLevel::weight() * 100
    |                 + min(fuentes distintas, max_source_bonus / source_bonus) * source_bonus
    |
    | Es un entero para poder ordenar en SQL: la columna `relevance` guarda un
    | slug en español ('baja'|'media'|'alta'|'critica') y ordenarla
    | alfabéticamente no significa nada.
    |
    */

    'relevance' => [
        'minimum_for_briefing' => env('NEWS_MIN_RELEVANCE', 'media'),
        'source_bonus' => 5,
        'max_source_bonus' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agrupación de artículos en acontecimientos
    |--------------------------------------------------------------------------
    */

    'clustering' => [
        'window_hours' => (int) env('NEWS_CLUSTER_WINDOW', 24),
        'title_similarity' => (float) env('NEWS_CLUSTER_THRESHOLD', 0.62),
        'shared_entities_minimum' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Briefings
    |--------------------------------------------------------------------------
    */

    'briefing' => [
        'events_per_edition' => (int) env('NEWS_EVENTS_PER_BRIEFING', 7),
        'timezone' => 'America/Santiago',
    ],

    /*
    |--------------------------------------------------------------------------
    | Datos de mercado
    |--------------------------------------------------------------------------
    |
    | Los metadatos de presentación se copian al snapshot al capturarlo, para
    | que cada fila del histórico sea autodescriptiva aunque cambie esta lista.
    |
    */

    'markets' => [
        'history_sessions' => 10,

        'instruments' => [
            ['symbol' => '^IPSA', 'name' => 'IPSA', 'detail' => 'Bolsa de Santiago', 'unit' => 'pts'],
            ['symbol' => 'CLP=X', 'name' => 'USD / CLP', 'detail' => 'Dólar observado', 'unit' => '$'],
            ['symbol' => 'HG=F', 'name' => 'Cobre', 'detail' => 'COMEX, US$/lb', 'unit' => 'US$'],
            ['symbol' => 'BZ=F', 'name' => 'Brent', 'detail' => 'Crudo, US$/bbl', 'unit' => 'US$'],
            ['symbol' => '^GSPC', 'name' => 'S&P 500', 'detail' => 'Estados Unidos', 'unit' => 'pts'],
            ['symbol' => 'BTC-USD', 'name' => 'Bitcoin', 'detail' => 'US$', 'unit' => 'US$'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scraping responsable
    |--------------------------------------------------------------------------
    |
    | Requisito, no opcional (CLAUDE.md §4): User-Agent identificable, retardo
    | entre requests y respeto a robots.txt. No se eluden paywalls.
    |
    */

    'scraping' => [
        'user_agent' => env('NEWS_USER_AGENT', 'NewsScraperBot/1.0 (proyecto academico IIP323W UDD)'),
        'delay_seconds' => (int) env('NEWS_SCRAPE_DELAY', 2),
        'request_timeout' => (int) env('NEWS_SCRAPE_TIMEOUT', 20),
        'respect_robots_txt' => true,
        'max_articles_per_source' => (int) env('NEWS_MAX_ARTICLES', 25),
    ],

];
