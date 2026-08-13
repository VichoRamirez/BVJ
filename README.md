# BVJ — Agente de Coyuntura

Agente de coyuntura (scraper + análisis de noticias) construido en Laravel. El proyecto recolecta noticias desde distintas fuentes y usa un modelo de lenguaje (vía Ollama) para procesarlas y generar análisis de coyuntura.

Inspirado en [Agentes_de_Coyuntura](https://github.com/DataMarketAnalysisClub/Agentes_de_Coyuntura) de DataMarketAnalysisClub.

> Proyecto en etapa inicial: por ahora contiene el esqueleto base de Laravel. La lógica de scraping y análisis de noticias todavía no está implementada.

## Stack

- PHP 8.5 / Laravel 13
- Pest 5 (testing)
- Tailwind CSS 4 + Vite
- Ollama (LLM para el análisis de coyuntura)
- SQLite (base de datos por defecto)

## Requisitos

- PHP >= 8.3
- Composer
- Node.js + npm
- Ollama local ejecutándose en `http://127.0.0.1:11434`

## Instalación

```bash
composer install
npm install
```

Copia el archivo de entorno y genera la clave de la aplicación:

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

Alternativamente, `composer run setup` ejecuta estos pasos automáticamente.

### Configuración de Ollama

La integración usa únicamente Ollama local. En `.env`, configura el driver y un modelo instalado localmente:

```
NEWS_AI_DRIVER=ollama
NEWS_OLLAMA_BASE_URL=http://127.0.0.1:11434
NEWS_OLLAMA_MODEL=llama3.2:3b
NEWS_OLLAMA_CONNECT_TIMEOUT=3
NEWS_OLLAMA_TIMEOUT=60
NEWS_OLLAMA_RETRY_ATTEMPTS=2
NEWS_OLLAMA_RETRY_BACKOFF=100
NEWS_OLLAMA_MAX_RESPONSE_BYTES=1048576
```

La URL debe usar HTTP, un literal IP loopback (`127.0.0.1` o `[::1]`) y un puerto explícito.

## Desarrollo

Levanta servidor, cola, logs y Vite en paralelo:

```bash
composer run dev
```

## Tests

```bash
composer run test
```

o de forma más específica:

```bash
php artisan test --compact
```

## Formato de código

```bash
vendor/bin/pint
```
