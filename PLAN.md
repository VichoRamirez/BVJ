# PLAN.md — Plan de implementación de NewsScraper

Plan de trabajo para el proyecto integrador de la Unidad 3 (Laravel), IIP323W · 2026-2.
Contexto, decisiones y reglas están en `CLAUDE.md`; el alcance viene de `NewScrapper-propuesta-laravel.pdf`.

**Estado actual:** el dominio está implementado. Existen las doce tablas del modelo de datos con sus modelos, factories y seeders, y las seis rutas del frontend leen de la base de datos (ya no de datos de demostración). Falta el pipeline: scraping, capa de IA, agrupación y scheduler.

> **Bloqueo de entorno:** el `composer.lock` fue resuelto en PHP 8.4+ (`symfony/*` 8.1 pide `php >=8.4.1`, y `pestphp/pest ^5` pide `php ^8.4` con PHPUnit 13). **El proyecto no corre en PHP 8.3.** Cada integrante necesita PHP 8.4 o 8.5 instalado; con 8.3 el `composer install` falla en la resolución.

---

## 0. Arquitectura en una frase

Un **pipeline por lotes** que corre dos veces al día:

```
Scheduler ──> ScrapeSourceJob (Roach, 1 por Source)
                 └─> Article (crudo, deduplicado por hash de URL)
                       └─> AnalyzeArticleJob (LLM → JSON validado)
                             └─> Analysis + Entities + Tags
                                   └─> ClusterArticlesJob (agrupa Articles → Event)
                                         └─> GenerateBriefingJob (top N Events → Briefing)
                                               └─> Blade lee de la BD (sin llamadas externas en request)
```

Todo lo externo ocurre en colas. Las vistas solo leen tablas ya escritas.

---

## 1. Modelo de datos

Migraciones en orden de dependencia:

| Tabla | Columnas clave | Notas |
|---|---|---|
| `sources` | `name`, `slug` (unique), `base_url`, `spider_class`, `is_active`, `last_scraped_at`, `failure_count` | Sembradas por seeder |
| `articles` | `source_id`, `event_id` (null), `url`, `url_hash` (unique), `title`, `author`, `published_at`, `excerpt`, `content`, `scraped_at`, `analysis_status` | `url_hash` = sha256 de la URL normalizada (`App\Support\CanonicalUrl`) → idempotencia |
| `analyses` | `article_id` (unique), `provider`, `model`, `schema_version`, `summary`, `category`, `relevance`, `importance_explanation`, `raw_response` (json), `analyzed_at` | 1:1 con `articles` |
| `entities` | `type` (company/person), `name`, `slug`, unique(`type`,`slug`) | |
| `article_entity` | `article_id`, `entity_id` | pivote |
| `entity_event` | `entity_id`, `event_id` | pivote; lo sincroniza `Event::syncAggregatesFromArticles()` |
| `tags` | `name`, `slug` (unique) | |
| `article_tag` | `article_id`, `tag_id` | pivote |
| `events` | `slug` (unique), `title`, `summary`, `importance`, `category`, `relevance`, `relevance_score`, `tags` (json), `first_seen_at`, `articles_count` | Un Event agrupa N Articles |
| `briefings` | `edition` (morning/evening), `published_on` (date), `published_at`, unique(`published_on`,`edition`) | |
| `briefing_event` | `briefing_id`, `event_id`, `position` | orden de presentación |
| `market_snapshots` | `symbol`, `name`, `detail`, `unit`, `price`, `change_percent`, `history` (json), `sort_order`, `captured_at`, unique(`symbol`,`captured_at`) | Yahoo Finance, para gráficos |

**Enums** (`app/Enums/`): `NewsCategory`, `RelevanceLevel` (`Low`/`Medium`/`High`/`Critical`), `BriefingEdition`, `EntityType`, `AnalysisStatus`.

Índices: `articles(published_at)`, `articles(event_id, published_at)`, `events(relevance_score, first_seen_at)`, `events(category, relevance_score)`, `analyses(category)`.

**Decisiones del esquema que conviene tener presentes:**

- La columna se llama **`relevance`**, no `relevance_level`: manda el nombre que ya usan las vistas.
- `events` va **denormalizado**. Lleva su propio `summary`, `importance`, `relevance` y `tags` copiados del análisis líder del cluster, para que las vistas lean una sola fila sin joins. Quien mantiene esa coherencia es `Event::syncAggregatesFromArticles()`, que hoy usan el seeder y las factories y en la Semana 3 usará `ClusterArticlesJob`.
- El orden "del más relevante al menos relevante" se resuelve **en SQL sobre `relevance_score`** (entero). `relevance` guarda un slug en español y ordenarlo alfabéticamente daría `alta < baja < critica < media`.
- `events.articles_count` es una columna física: **nunca usar `withCount('articles')`** sobre `Event`, colisiona con el atributo generado.
- `events.tags` es json (proyección de presentación); los tags analíticos por artículo viven normalizados en `tags` + `article_tag`.

---

## 2. Semana 1 — Cimientos (responsable: Joaquín)

*La propuesta asigna la Semana 1 a "Propuesta + Mockup" (Vicente, Bruno), que ya está entregada. Esta semana se usa para levantar la base técnica en paralelo.*

- [x] `composer install` + `npm install`, crear `database/database.sqlite`, `php artisan migrate`. El script `composer run setup` ya crea el sqlite (antes solo lo hacía `post-create-project-cmd`, que no corre al clonar) y siembra la base. **Requiere PHP 8.4+**, ver el bloqueo de entorno más arriba.
- [x] **Pedir aprobación e instalar dependencias.** Solo entra `scheb/yahoo-finance-api` (v5.2, `php >=8.1`, sin dependencia de framework); queda por correr el `composer require` cuando el entorno tenga PHP 8.4+. Las otras dos quedaron fuera:
    - `roach-php/laravel` **no es instalable en Laravel 13** (su última versión, 3.2.0, declara `laravel/framework ^10|^11|^12`). Ver §3.1.
    - `laraveldaily/laravel-charts` (0.2.3, junio 2023) no declara ninguna dependencia en su `composer.json` y nunca fue probada contra Laravel 13. Los sparklines SVG propios ya cubren el requisito, así que se descartó.
- [x] Agregar `config/newsscraper.php` (driver de IA, umbrales de relevancia, N eventos por briefing, símbolos de mercado a seguir) y las variables nuevas a `.env.example`.
- [x] Crear los Enums del dominio. Los cinco están en uso como casts en los modelos.
- [x] Migraciones + modelos + factories + seeders (`SourceSeeder` con las cinco fuentes del MVP).
- [x] Definir relaciones Eloquent con tipado explícito (`Article::source()`, `Article::analysis()`, `Article::entities()`, `Event::articles()`, `Briefing::events()`).
- [ ] Layout Blade base + Tailwind funcionando. *(el layout y los tokens están; falta emitir `@fonts` en `resources/views/components/layouts/app.blade.php` —hoy Archivo nunca carga porque solo lo emitía el `welcome.blade.php` que se eliminó— más la revisión móvil y de modo oscuro, que firma Vicente)*

**Hecho cuando:** `php artisan migrate:fresh --seed` corre limpio y un test de factories crea Article + Analysis + Event. ✔ `tests/Feature/DomainFactoriesTest.php`.

---

## 3. Semana 2 — Rutas, vistas y datos (responsable: Joaquín)

### 3.1 Scraping

> **Decidir antes de escribir el primer spider: Roach no entra en Laravel 13.**
> `roach-php/laravel` 3.2.0 declara `laravel/framework: ^10.0 || ^11.0 || ^12.0`, así que
> `composer require` falla en la resolución. Y `roach-php/core` 3.2.1 exige `symfony/* ^7.0`
> mientras el lock tiene Symfony 8.1. Dos salidas evaluadas:
>
> - **A.** Instalar solo `roach-php/core` y escribir el pegamento a Laravel a mano (~40 líneas:
>   config, fachada, logger y contenedor). Obliga a degradar todo Symfony a la línea 7.4;
>   correr `composer require roach-php/core --dry-run -W` primero y verificar que no arrastre
>   a `laravel/framework`, `laravel/boost` ni `laravel/pail`.
> - **B.** Descartar Roach. `symfony/dom-crawler` + `symfony/css-selector` + `spatie/robots-txt`
>   (los tres con release ^8, cero conflictos) sobre el HTTP client de Laravel, detrás de un
>   contrato `App\Contracts\SourceScraper`, un spider por fuente en `app/Spiders/`, con el mismo
>   `delay`, User-Agent y respeto a `robots.txt` que ya están en `config/newsscraper.php`.
>
> Si gana B, hay que corregir la tabla de decisiones de `CLAUDE.md §3`.

- [ ] `app/Spiders/DiarioFinancieroSpider.php` y `BloombergSpider.php`: extraer título, URL, autor, fecha, bajada y cuerpo.
- [ ] `ScrapeSourceJob`: corre un spider, normaliza URLs, persiste con `updateOrCreate` sobre `url_hash`, marca `analysis_status = pending`.
- [ ] Manejo de fallos por fuente: try/catch por Source, incrementar `failure_count`, log estructurado, **nunca** tumbar el lote completo.
- [ ] Respetar `robots.txt`, `$delay` entre requests y User-Agent propio.
- [ ] Comando `php artisan news:scrape {--source=}` para correr manualmente.

### 3.2 Rutas y vistas (con datos de factories mientras la IA no esté lista)

> **Conectado.** Las seis rutas leen de la base de datos con eager loading; `DemoContent` fue
> eliminado. Los datos de la demo viven ahora en `DemoSeeder`. Sigue abierto a cambios de diseño:
> ver `AUDITORIA-UI.md` para las decisiones de interfaz.

| Ruta | Nombre | Contenido |
|---|---|---|
| `/` | `home` | Último briefing publicado |
| `/briefings` | `briefings.index` | Histórico paginado |
| `/briefings/{briefing}` | `briefings.show` | Briefing específico (AM/PM) |
| `/eventos/{event}` | `events.show` | Evento + todos sus artículos y fuentes |
| `/categorias/{category}` | `categories.show` | Eventos filtrados por categoría |
| `/mercados` | `markets.index` | Gráficos de Yahoo Finance |

- [ ] Componentes Blade reutilizables: `<x-event-card>`, `<x-relevance-badge>`, `<x-source-pill>`, `<x-entity-list>`. *(borrador levantado, sujeto a revisión)*
- [ ] Diseño escaneable: tarjeta = título, badge de relevancia, categoría, fuentes, resumen de 2–3 líneas, entidades, enlace al original. Responsive y con aviso "resumen generado por IA". *(borrador levantado, falta revisión visual en móvil y modo oscuro)*
- [ ] Estados vacío / carga / error del pipeline asíncrono. *(vacío —portada y mercados— y fuente caída hechos; falta carga)*
- [x] Reemplazar `DemoContent` por consultas Eloquent con eager loading una vez existan los modelos. `Model::preventLazyLoading()` está activo fuera de producción, así que un eager load faltante rompe los tests en vez de esconderse como N+1.

**Hecho cuando:** todas las rutas renderizan con datos sembrados **desde la base de datos** (no de demostración) y hay feature tests de smoke por cada una. ✔

---

## 4. Semana 3 — IA y funcionalidad principal (responsables: Bruno, Vicente)

### 4.1 Capa de IA (Bruno)
- [ ] `App\Contracts\NewsAnalyzer` con `analyze(Article $article): AnalysisResult`.
- [ ] `App\Services\Ai\OllamaAnalyzer` usando el HTTP client de Laravel con timeout y `retry()`.
- [ ] `AnalysisResult` como DTO tipado (resumen, categoría, relevancia, empresas, personas, tags, explicación).
- [ ] Prompt en `resources/prompts/analyze-article.blade.php`: pide JSON estricto, en español, con categorías del enum y prohibición explícita de inventar datos que no estén en el texto.
- [ ] Validación de la respuesta con `Validator` contra un esquema; en fallo → 1 reintento → `AnalysisStatus::Failed` + log. Guardar siempre `raw_response`.
- [ ] `AnalyzeArticleJob` con `ThrottlesExceptions` / backoff; encolar entidades y tags con `firstOrCreate` (normalizando nombres para no duplicar "Codelco" vs "CODELCO").
- [ ] Binding del driver en `AppServiceProvider` según `config('newsscraper.ai.driver')` → deja lista la ruta para un `GeminiAnalyzer` si el profesor lo exige.

### 4.2 Agrupación y priorización (Bruno + Vicente)
- [ ] `ClusterArticlesJob`: dentro de una ventana de 24 h, agrupar artículos por (a) similitud de títulos normalizados, (b) entidades compartidas, (c) misma categoría. Umbral configurable.
- [ ] Crear o reutilizar `Event`; `relevance_score` = relevancia máxima del cluster + bonus por número de fuentes distintas.
- [ ] `GenerateBriefingJob`: toma los Events del período (AM: desde el briefing anterior), ordena por `relevance_score`, corta en N (config, por defecto 7) y crea `Briefing` + pivote ordenado.

### 4.3 Automatización
- [ ] Scheduler en `routes/console.php`: pipeline a las **07:00** (edición `morning`) y **18:00** (`evening`), zona `America/Santiago`, con `withoutOverlapping()` y `onOneServer()`.
- [ ] Comando orquestador `php artisan news:pipeline {--edition=}` que encadena scrape → analyze → cluster → briefing (usable a mano para la demo).
- [ ] `FetchMarketSnapshotsJob` para Yahoo Finance + gráficos en `/mercados`.

**Hecho cuando:** `php artisan news:pipeline --edition=morning` genera un briefing real end-to-end.

---

## 5. Semana 4 — Cierre, pruebas y presentación (todo el equipo)

- [ ] Cobertura de tests: parseo del LLM (JSON válido, inválido, campos faltantes) y clustering. *(hechos: deduplicación por `url_hash`, orden del briefing y smoke de las 6 rutas)*
- [ ] Estados vacíos y de error en la UI (sin briefing aún, fuente caída, análisis fallido). *(los tres primeros hechos)*
- [x] Seeder de demo (`DemoSeeder`) con un par de briefings realistas, por si el scraping falla en vivo durante la presentación. **Plan B obligatorio.** Adelantado a la Semana 1: 13 acontecimientos, 5 ediciones y 6 instrumentos, con fechas relativas a hoy.
- [ ] `vendor/bin/pint` sobre todo el proyecto.
- [ ] README actualizado: instalación, configuración de la IA, cómo correr el pipeline a mano.
- [ ] Confirmar con el profesor el tema Ollama vs. Gemini (ver `CLAUDE.md` §3) y, si corresponde, implementar `GeminiAnalyzer` — con la interfaz ya hecha es ~1 clase.
- [ ] Guion de la demo: mostrar briefing → abrir un evento con 2+ fuentes → mostrar el enlace original → gráficos de mercado.

---

## 6. Riesgos y mitigaciones

| Riesgo (propuesta §10) | Mitigación en el plan |
|---|---|
| Cambios de HTML rompen scrapers | Spiders aislados por fuente + fixtures en tests que detectan el quiebre |
| Bloqueo de scraping / paywalls | Fuente se marca inactiva, se registra el fallo; no se elude nada. Mínimo 2 fuentes de respaldo con RSS |
| Alucinaciones de la IA | Validación de esquema, `raw_response` guardado, enlace al original siempre visible, aviso de contenido generado |
| Clasificación incorrecta | Categorías cerradas por enum; relevancia acotada 1–4 |
| Duplicados no detectados | Clustering por título + entidades + ventana temporal; umbral ajustable sin tocar código |
| Caída de servicios externos | Todo en colas con reintentos; la web sirve el último briefing persistido |
| Límites de API | Throttling en los jobs, 2 corridas diarias, análisis solo de artículos nuevos |
| Demo en vivo falla | `DemoSeeder` con datos ya generados |

---

## 7. Orden de ejecución sugerido

1. Migraciones + modelos + factories (desbloquea a todos)
2. Vistas Blade con factories (Vicente avanza sin depender de la IA)
3. Spiders (datos reales)
4. Capa de IA detrás de la interfaz
5. Clustering + briefing
6. Scheduler + mercados
7. Tests, pulido, demo

Los pasos 2 y 3 son paralelizables; el 4 solo necesita que exista el modelo `Article`.
