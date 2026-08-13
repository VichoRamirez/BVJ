<?php

declare(strict_types=1);
use App\Spiders\BbcBusinessSpider;
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

        /*
        | OpenRouter: API compatible con OpenAI, con modelos gratuitos. La clave
        | se lee solo desde acá, nunca con env() fuera de config, y jamás se
        | escribe en un log ni en un mensaje de error.
        |
        | `model` es el que se usa cuando no se especifica otro; la cadena de
        | respaldo de abajo puede pedir uno distinto por eslabón.
        */
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'model' => env('OPENROUTER_MODEL', 'google/gemma-4-26b-a4b-it:free'),
            // Opcionales: OpenRouter los usa para atribuir el tráfico.
            'referer' => env('OPENROUTER_SITE_URL'),
            'title' => env('OPENROUTER_SITE_NAME', 'NewsScraper'),
            'connect_timeout' => (float) env('OPENROUTER_CONNECT_TIMEOUT', 5),
            'timeout' => (float) env('OPENROUTER_TIMEOUT', 60),
            'retry_attempts' => (int) env('OPENROUTER_RETRY_ATTEMPTS', 2),
            'retry_backoff' => (int) env('OPENROUTER_RETRY_BACKOFF', 500),
            'max_response_bytes' => (int) env('OPENROUTER_MAX_RESPONSE_BYTES', 1_048_576),
        ],

        /*
        |----------------------------------------------------------------------
        | Cadena de respaldo
        |----------------------------------------------------------------------
        |
        | Con NEWS_AI_DRIVER=chain se prueban estos eslabones en orden y gana el
        | primero que responda. Sirve para dos cosas a la vez: caerse a la nube
        | cuando Ollama local no está, y rotar entre modelos gratuitos de
        | OpenRouter, que tienen cuota baja y devuelven 429 seguido.
        |
        | Un eslabón mal configurado (sin API key, sin Ollama instalado) no rompe
        | la cadena: se salta. Por eso se puede dejar Ollama primero aunque
        | todavía no esté instalado.
        |
        | Los modelos gratuitos listados soportan salida estructurada, que es lo
        | que hace que el JSON cumpla el esquema. Verificar en
        | https://openrouter.ai/models antes de cambiarlos: la oferta gratuita rota.
        |
        */
        'chain' => [
            ['driver' => 'ollama'],
            ['driver' => 'openrouter', 'model' => 'google/gemma-4-26b-a4b-it:free'],
            ['driver' => 'openrouter', 'model' => 'openai/gpt-oss-20b:free'],
            ['driver' => 'openrouter', 'model' => 'nvidia/nemotron-nano-9b-v2:free'],
        ],

        'fallback' => [
            'circuit_breaker_failures' => (int) env('NEWS_AI_CIRCUIT_FAILURES', 3),
            'circuit_breaker_ttl' => (int) env('NEWS_AI_CIRCUIT_TTL', 300),

            /*
            | Si además hay que cambiar de modelo cuando el JSON viene mal.
            | Apagado por defecto: una respuesta que no cumple el esquema suele
            | ser culpa del prompt, y saltar al siguiente modelo lo esconde.
            */
            'on_invalid_response' => (bool) env('NEWS_AI_FALLBACK_ON_INVALID', false),
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

        /*
        | El umbral de título se mantiene alto a propósito. Medido sobre 18
        | artículos reales, dos medios que cubren el mismo hecho comparten muy
        | poco vocabulario: el caso Sartor dio 0,25. Bajarlo hasta ahí agrupaba
        | ese caso, pero a ese nivel también se fusionan hechos distintos que
        | solo comparten jerga financiera.
        |
        | La señal buena son las entidades, así que manda una sola compartida y
        | el título queda como refuerzo, no como requisito.
        */
        'title_similarity' => (float) env('NEWS_CLUSTER_THRESHOLD', 0.62),
        'shared_entities_minimum' => 1,
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
        | Abrir cada nota para leer su fecha y su autor.
        |
        | Los listados chilenos casi no publican fecha (medido el 2026-08-13:
        | Diario Financiero 12 de 103 tarjetas, Pulso 0 de 77), y sin fecha real
        | todos los artículos de una corrida quedan con el mismo instante, que es
        | la hora del scrape. La agrupación y el corte del briefing se miden
        | contra `published_at`, así que la fecha no es cosmética.
        |
        | Solo lo usan las arañas HTML; las de RSS ya reciben `pubDate`. Cuesta
        | un request extra por artículo, con el mismo retardo y robots.txt de
        | siempre. Apagarlo devuelve el comportamiento anterior.
        */
        'fetch_article_metadata' => filter_var(env('NEWS_SCRAPE_FETCH_METADATA', true), FILTER_VALIDATE_BOOLEAN),

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
            BbcBusinessSpider::class,
            DiarioFinancieroSpider::class,
            PulsoSpider::class,
        ],
    ],

];
