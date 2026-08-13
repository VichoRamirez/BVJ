# BVJ — Agente de Coyuntura

Agente de coyuntura (scraper + análisis de noticias) construido en Laravel. El proyecto recolecta noticias desde distintas fuentes y usa un modelo de lenguaje (vía Ollama) para procesarlas y generar análisis de coyuntura.

Inspirado en [Agentes_de_Coyuntura](https://github.com/DataMarketAnalysisClub/Agentes_de_Coyuntura) de DataMarketAnalysisClub.

> Proyecto en curso. El frontend, el modelo de datos, el pipeline completo (recolección →
> análisis → agrupación → briefing) y la captura de datos de mercado están implementados y
> probados. Lo único que falta es la extracción de noticias: los spiders de cada fuente —
> ver `PLAN.md`.

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
php artisan migrate --seed
```

El `--seed` carga las fuentes del MVP y el contenido de demostración (`DemoSeeder`): 13
acontecimientos, 5 ediciones y 6 instrumentos de mercado, con fechas relativas a hoy. Es
idempotente, se puede volver a correr sin duplicar nada.

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

### Datos de mercado

**No hay que configurar ninguna API key.** El endpoint de gráficos de Yahoo Finance
(`/v8/finance/chart`) es público. Lo único que exige es un User-Agent declarado: con el de
`curl` por defecto responde 429, con `NEWS_USER_AGENT` responde 200. Los instrumentos que se
siguen están en `config/newsscraper.php`, no en `.env`.

```bash
php artisan news:markets
```

> `^IPSA` viene congelado desde Yahoo (última cotización del 2026-07-17), así que aparece con
> variación 0,00% y sin serie. Los otros cinco instrumentos responden al día. Ver `PLAN.md §4.3`.

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

## El pipeline

Cuatro etapas encadenadas, cada una dentro de un Job. Toda llamada externa vive ahí dentro;
los controladores solo leen tablas ya escritas.

```
news:pipeline
  ├─ ScrapeSourceJob          un spider por fuente → Article (dedup por url_hash)
  ├─ AnalyzeArticleJob        LLM → JSON validado → Analysis + Entities + Tags
  ├─ ClusterArticlesJob       agrupa Articles de distintos medios → Event
  ├─ FetchMarketSnapshotsJob  Yahoo Finance → MarketSnapshot
  └─ GenerateBriefingJob      top N Events del período → Briefing
```

El scheduler lo corre a las **07:00** y a las **18:00** de Chile. Para que eso ocurra, en el
servidor tiene que estar corriendo `php artisan schedule:work` (o el cron equivalente).

A mano:

```bash
php artisan news:pipeline --edition=morning   # corrida completa
php artisan news:pipeline --skip-scrape       # analizar y publicar lo que ya está en la base
php artisan news:scrape --source=diario-financiero --sync
php artisan news:markets                      # solo las cotizaciones
php artisan schedule:list                     # ver los horarios registrados
```

Las cuatro etapas del pipeline corren **en orden dentro del proceso del comando**, no repartidas
en la cola: agrupar antes de que terminen los análisis produciría acontecimientos incompletos.
`news:scrape`, en cambio, encola por defecto (usa `--sync` si no tienes un worker levantado).

> **Todavía no hay spiders.** `sources.spider_class` está vacío en las cinco fuentes, así que
> hoy `news:scrape` registra "sin spider configurado" como fallo de cada fuente y no recolecta
> nada. Es el estado esperado: falta escribir las implementaciones de
> `App\Contracts\SourceScraper`. Mientras tanto, el contenido de las vistas lo pone `DemoSeeder`.

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
