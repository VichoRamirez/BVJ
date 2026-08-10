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
- Acceso a una API de Ollama (local o [ollama.com](https://ollama.com))

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

En `.env`, define las credenciales del modelo:

```
OLLAMA_API_KEY=tu-api-key
OLLAMA_MODEL=gpt-oss:20b-cloud
OLLAMA_API_URL=https://ollama.com/api/chat
```

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
