<?php

declare(strict_types=1);
use App\Spiders\BbcMundoEconomiaSpider;
use App\Spiders\DiarioFinancieroSpider;
use App\Spiders\PulsoSpider;

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
    | El bloque `ollama` lo consume App\Services\Ai\OllamaAnalyzer, que valida
    | estas claves al construirse y solo acepta un host local. No renombrarlas
    | sin ajustar ese servicio.
    |
    */

    'ai' => [
        'driver' => env('NEWS_AI_DRIVER', 'unconfigured'),

        'ollama' => [
            'base_url' => env('NEWS_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
            'model' => env('NEWS_OLLAMA_MODEL', 'llama3.2:3b'),
            'connect_timeout' => (float) env('NEWS_OLLAMA_CONNECT_TIMEOUT', 3),
            'timeout' => (float) env('NEWS_OLLAMA_TIMEOUT', 60),
            'retry_attempts' => (int) env('NEWS_OLLAMA_RETRY_ATTEMPTS', 2),
            'retry_backoff' => (int) env('NEWS_OLLAMA_RETRY_BACKOFF', 100),
            'max_response_bytes' => (int) env('NEWS_OLLAMA_MAX_RESPONSE_BYTES', 1_048_576),
        ],

        'job_tries' => (int) env('NEWS_AI_JOB_TRIES', 4),
        'job_timeout' => (int) env('NEWS_AI_JOB_TIMEOUT', 180),
        'overlap_release_after' => (int) env('NEWS_AI_OVERLAP_RELEASE_AFTER', 30),
        'processing_stale_after' => (int) env('NEWS_AI_PROCESSING_STALE_AFTER', 300),
        'overlap_ttl' => max(
            (int) env('NEWS_AI_OVERLAP_TTL', 240),
            (int) env('NEWS_AI_JOB_TIMEOUT', 180) + 31,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Relevancia
    |--------------------------------------------------------------------------
    |
    | relevance_score = RelevanceLevel::weight() * 100
    |                 + min(fuentes distintas * source_bonus, max_source_bonus)
    |
    | Es un entero para poder ordenar en SQL: la columna `relevance` guarda el
    | valor del enum y ordenarlo alfabéticamente no significa nada.
    |
    */

    'relevance' => [
        'minimum_for_briefing' => env('NEWS_MIN_RELEVANCE', 'medium'),
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
        'max_articles' => (int) env('NEWS_CLUSTER_MAX_ARTICLES', 500),
        'job_timeout' => (int) env('NEWS_CLUSTER_JOB_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Briefings
    |--------------------------------------------------------------------------
    */

    'briefing' => [
        'events_per_edition' => (int) env('NEWS_EVENTS_PER_BRIEFING', 7),
        'timezone' => 'America/Santiago',
        'job_tries' => (int) env('NEWS_BRIEFING_JOB_TRIES', 3),
        'job_timeout' => (int) env('NEWS_BRIEFING_JOB_TIMEOUT', 120),
        'overlap_release_after' => (int) env('NEWS_BRIEFING_OVERLAP_RELEASE_AFTER', 120),
        'overlap_ttl' => max(
            (int) env('NEWS_BRIEFING_OVERLAP_TTL', 151),
            (int) env('NEWS_BRIEFING_JOB_TIMEOUT', 120) + 31,
        ),
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
        'provider' => env('NEWS_MARKETS_PROVIDER', 'yahoo'),

        'history_sessions' => 10,

        /*
        | Yahoo Finance no pide API key: /v8/finance/chart es un endpoint
        | abierto. Lo único que exige es un User-Agent declarado (reutiliza el
        | de `scraping`), y por eso acá no hay ninguna credencial que proteger.
        |
        | El rango es más ancho que `history_sessions` a propósito: incluye
        | feriados y sesiones sin datos, que se descartan al armar la serie.
        */
        'yahoo' => [
            'base_url' => 'https://query1.finance.yahoo.com',
            'range' => '1mo',
            'interval' => '1d',
            'timeout' => (int) env('NEWS_MARKETS_TIMEOUT', 15),
            'retry_attempts' => (int) env('NEWS_MARKETS_RETRY_ATTEMPTS', 2),
            'retry_backoff' => (int) env('NEWS_MARKETS_RETRY_BACKOFF', 500),
        ],

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
        'retry_attempts' => (int) env('NEWS_SCRAPE_RETRY_ATTEMPTS', 2),
        'retry_backoff' => (int) env('NEWS_SCRAPE_RETRY_BACKOFF', 500),
        'max_redirects' => (int) env('NEWS_SCRAPE_MAX_REDIRECTS', 3),
        'max_response_bytes' => (int) env('NEWS_SCRAPE_MAX_BYTES', 5_242_880),
        'robots_cache_ttl' => (int) env('NEWS_SCRAPE_ROBOTS_TTL', 3600),

        /*
        | Resolver el host y rechazarlo si apunta a una red privada (anti-SSRF).
        | Solo se apaga donde no hay DNS, como en los tests; la lógica de rangos
        | se prueba aparte en SafeHttpFetcher::isPublicAddress().
        */
        'verify_public_address' => (bool) env('NEWS_SCRAPE_VERIFY_PUBLIC_ADDRESS', true),

        /*
        | Lo que se guarda de cada artículo. Se recorta a propósito: hace falta
        | lo justo para que el LLM analice, y republicar el cuerpo completo de
        | un medio con copyright no está permitido (CLAUDE.md §4). Los spiders
        | RSS ni siquiera abren la nota: se quedan con la bajada del feed.
        */
        'max_content_chars' => (int) env('NEWS_MAX_CONTENT_CHARS', 4000),
        'max_excerpt_chars' => (int) env('NEWS_MAX_EXCERPT_CHARS', 600),

        /*
        | Allowlist de hosts. SafeHttpFetcher no sale a ningún dominio que no
        | esté acá, y revalida cada redirección contra la misma lista: una
        | fuente comprometida no puede empujar al bot hacia otro sitio ni hacia
        | la red interna.
        */
        'allowed_hosts' => [
            'feeds.bbci.co.uk',
            'www.df.cl',
            'www.latercera.com',
        ],

        /*
        | Allowlist de spiders. `sources.spider_class` es un dato de la base y
        | no puede terminar instanciando cualquier clase del proyecto: el
        | resolver solo acepta las de esta lista.
        */
        'spiders' => [
            BbcMundoEconomiaSpider::class,
            DiarioFinancieroSpider::class,
            PulsoSpider::class,
        ],
    ],

];
