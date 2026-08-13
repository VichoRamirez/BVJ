# BVJ — Agente de Coyuntura

Agente de coyuntura (scraper + análisis de noticias) construido en Laravel. El proyecto recolecta noticias desde distintas fuentes y usa un modelo de lenguaje (vía Ollama) para procesarlas y generar análisis de coyuntura.

Inspirado en [Agentes_de_Coyuntura](https://github.com/DataMarketAnalysisClub/Agentes_de_Coyuntura) de DataMarketAnalysisClub.

> Proyecto en curso. El frontend (rutas, vistas Blade y sistema de diseño) y el modelo de datos
> completo están implementados: las seis rutas leen de la base de datos. El scraping, el análisis
> por LLM y el pipeline de briefings todavía no están implementados — ver `PLAN.md`.

## Stack

- PHP 8.4+ / Laravel 13
- Pest 5 (testing)
- Tailwind CSS 4 + Vite
- Ollama (LLM para el análisis de coyuntura)
- SQLite (base de datos por defecto)

## Requisitos

- **PHP >= 8.4.** El `composer.json` dice `^8.3` por herencia del esqueleto, pero el `composer.lock`
  trae Symfony 8.1 (`php >=8.4.1`) y Pest 5 (`php ^8.4`): en PHP 8.3 el `composer install` falla.
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
php artisan migrate --seed
```

El `--seed` carga las fuentes del MVP y el contenido de demostración (`DemoSeeder`): 13
acontecimientos, 5 ediciones y 6 instrumentos de mercado, con fechas relativas a hoy. Es
idempotente, se puede volver a correr sin duplicar nada.

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

### En Windows usa `dev:win`

`composer run dev` **falla en Windows con código 1**. La causa es `laravel/pail` (el visor
de logs): requiere la extensión `pcntl`, que es POSIX y no existe en los builds de PHP para
Windows. Como `concurrently` corre con `--kill-others`, Pail muere al arrancar y arrastra a
los otros tres procesos.

Usa el script equivalente sin Pail:

```bash
composer run dev:win
```

Levanta servidor (`http://127.0.0.1:8000`), cola y Vite. Para ver los logs, en otra terminal:

```bash
tail -f storage/logs/laravel.log
```

> **Nota sobre Git Bash:** si usas la terminal de Git Bash, `composer` no resuelve porque
> bash no completa la extensión `.bat` automáticamente. Escribe `composer.bat run dev:win`,
> o usa PowerShell, donde `composer` funciona tal cual.

## Rutas

Los controladores leen de la base de datos con eager loading; ninguna vista hace llamadas
externas. Mientras no exista el pipeline, los datos los pone `DemoSeeder`.

| Ruta | Nombre | Contenido |
|---|---|---|
| `/` | `home` | Último briefing publicado |
| `/briefings` | `briefings.index` | Histórico de ediciones |
| `/briefings/{briefing}` | `briefings.show` | Una edición (AM/PM) |
| `/eventos/{event}` | `events.show` | Acontecimiento con todas sus fuentes |
| `/categorias/{category}` | `categories.show` | Acontecimientos por categoría |
| `/mercados` | `markets.index` | Panel de datos de mercado |

`/?vacio=1` fuerza el estado "todavía no hay briefing", útil para revisar ese caso en diseño.

El sistema de diseño y las decisiones de interfaz están en `AUDITORIA-UI.md`.

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
