# PLAN.md — Plan de implementación de NewsScraper

Plan de trabajo para el proyecto integrador de la Unidad 3 (Laravel), IIP323W · 2026-2.
Contexto, decisiones y reglas están en `CLAUDE.md`; el alcance viene de `NewScrapper-propuesta-laravel.pdf`.

**Estado actual:** esqueleto limpio de Laravel 13. Solo existen `User`, las migraciones base y la vista `welcome`. No hay dominio implementado, `vendor/` no está instalado.

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
| `articles` | `source_id`, `url`, `url_hash` (unique), `title`, `author`, `published_at`, `excerpt`, `content`, `event_id` (null), `scraped_at`, `analysis_status` | `url_hash` = sha256 de la URL normalizada → idempotencia |
| `analyses` | `article_id` (unique), `model`, `summary`, `category`, `relevance_level`, `importance_explanation`, `raw_response` (json), `analyzed_at` | 1:1 con `articles` |
| `entities` | `type` (company/person), `name`, `slug` (unique con type) | |
| `article_entity` | `article_id`, `entity_id` | pivote |
| `tags` | `name`, `slug` (unique) | |
| `article_tag` | `article_id`, `tag_id` | pivote |
| `events` | `title`, `slug`, `category`, `relevance_score`, `first_seen_at`, `articles_count` | Un Event agrupa N Articles |
| `briefings` | `edition` (morning/evening), `published_on` (date), `published_at`, unique(`published_on`,`edition`) | |
| `briefing_event` | `briefing_id`, `event_id`, `position` | orden de presentación |
| `market_snapshots` | `symbol`, `price`, `change_percent`, `captured_at` | Yahoo Finance, para gráficos |

**Enums** (`app/Enums/`): `NewsCategory`, `RelevanceLevel` (`Low`/`Medium`/`High`/`Critical`), `BriefingEdition`, `EntityType`, `AnalysisStatus`.

Índices: `articles(published_at)`, `articles(event_id)`, `events(relevance_score)`, `analyses(category)`.

---

## 2. Semana 1 — Cimientos (responsable: Joaquín)

*La propuesta asigna la Semana 1 a "Propuesta + Mockup" (Vicente, Bruno), que ya está entregada. Esta semana se usa para levantar la base técnica en paralelo.*

- [ ] `composer install` + `npm install`, crear `database/database.sqlite`, `php artisan migrate`. *(hecho en local; falta verificar en las máquinas del resto del equipo)*
- [ ] **Pedir aprobación e instalar dependencias**: `roach-php/laravel`, `scheb/yahoo-finance-api`, `laraveldaily/laravel-charts`.
- [ ] Agregar `config/newsscraper.php` (driver de IA, umbrales de relevancia, N eventos por briefing, símbolos de mercado a seguir) y las variables nuevas a `.env.example`.
- [ ] Crear los Enums del dominio. *(en progreso: los cinco existen en `app/Enums/` con sus etiquetas; falta usarlos como casts en los modelos)*
- [ ] Migraciones + modelos + factories + seeders (`SourceSeeder` con Diario Financiero y Bloomberg).
- [ ] Definir relaciones Eloquent con tipado explícito (`Article::source()`, `Article::analysis()`, `Article::entities()`, `Event::articles()`, `Briefing::events()`).
- [ ] Layout Blade base + Tailwind funcionando. *(en progreso: el layout vive en `resources/views/components/layouts/app.blade.php` y los tokens del sistema de diseño están en `resources/css/app.css`)*

**Hecho cuando:** `php artisan migrate:fresh --seed` corre limpio y un test de factories crea Article + Analysis + Event.

---

## 3. Semana 2 — Rutas, vistas y datos (responsable: Joaquín)

### 3.1 Scraping
- [ ] `app/Spiders/DiarioFinancieroSpider.php` y `BloombergSpider.php` (Roach): extraer título, URL, autor, fecha, bajada y cuerpo.
- [ ] `ScrapeSourceJob`: corre un spider, normaliza URLs, persiste con `updateOrCreate` sobre `url_hash`, marca `analysis_status = pending`.
- [ ] Manejo de fallos por fuente: try/catch por Source, incrementar `failure_count`, log estructurado, **nunca** tumbar el lote completo.
- [ ] Respetar `robots.txt`, `$delay` entre requests y User-Agent propio.
- [ ] Comando `php artisan news:scrape {--source=}` para correr manualmente.

### 3.2 Rutas y vistas (con datos de factories mientras la IA no esté lista)

> **En progreso.** Las seis rutas, el layout, las vistas y los componentes Blade ya están
> levantados y con tests de smoke, pero sobre `app/Support/DemoContent.php` (datos de
> demostración), no sobre factories: los modelos todavía no existen. Queda abierto a cambios
> de diseño y de estructura — nada aquí es definitivo hasta que se conecte la base de datos y
> las vistas se prueben con datos reales. Ver `AUDITORIA-UI.md` para las decisiones de interfaz.

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
- [ ] Estados vacío / carga / error del pipeline asíncrono. *(vacío y fuente caída hechos; falta carga)*
- [ ] Reemplazar `DemoContent` por consultas Eloquent con eager loading una vez existan los modelos.

**Hecho cuando:** todas las rutas renderizan con datos sembrados **desde la base de datos** (no de demostración) y hay feature tests de smoke por cada una.

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

- [ ] Cobertura de tests: parseo del LLM (JSON válido, inválido, campos faltantes), deduplicación por `url_hash`, clustering, selección y orden del briefing, smoke de las 6 rutas. Todos con `Http::fake()` y fixtures — sin red.
- [ ] Estados vacíos y de error en la UI (sin briefing aún, fuente caída, análisis fallido).
- [ ] Seeder de demo (`DemoSeeder`) con un par de briefings realistas, por si el scraping falla en vivo durante la presentación. **Plan B obligatorio.**
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
