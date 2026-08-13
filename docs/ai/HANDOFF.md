# Handoff Técnico — 2026-08-12

Estado del proyecto al finalizar la sesión de trabajo sobre persistencia de análisis y clustering.

---

## Estado de ramas y commits

| Rama | Último commit | Dependencia |
|---|---|---|
| `main` | `06a3d95` — merge: integrar la capa de IA y clustering con el modelo de datos | base |
| `feature/analysis-persistence` | `c1505b0` — feat: persistir análisis de artículos | sobre `main` |
| `feature/article-clustering-persistence` | `17a7801` — feat: persistir agrupación de artículos | sobre `feature/analysis-persistence` (dependiente) |

**Merge order:** `main` ← `feature/analysis-persistence` ← `feature/article-clustering-persistence`. La segunda rama incluye todos los cambios de la primera.

---

## Funcionalidades implementadas en esta sesión

### 1. AnalyzeArticleJob (`app/Jobs/AnalyzeArticleJob.php`)
- Job con lease atómico basado en `run_id` (UUID) y columna `analysis_run_id` en `articles`.
- Estado: `Pending → Processing → Completed | Failed` vía `AnalysisStatus` enum.
- Exclusión mutua: `WithoutOverlapping('article:'.$id)` + `lockForUpdate()` dentro de la transacción.
- Rescate de jobs estancados: si `analysis_started_at` > `processing_stale_after` (default 300s), otro worker puede reclamar.
- Backoff: `[10, 30, 60]` segundos, máx 4 intentos (configurable).
- Persiste: `Analysis` 1:1 con `Article`, entidades (`Entity::firstOrCreateFor`), tags (`Tag::firstOrCreateFor`) con normalización de nombres.
- Excepción `AnalysisLeaseLostException` si el lock se pierde dentro de la transacción (idempotente, no falla el job).
- `raw_response` se guarda siempre, incluso en parseo parcial.

### 2. ClusterArticlesJob (`app/Jobs/ClusterArticlesJob.php`)
- Agrupa artículos con `analysis_status = Completed` y `event_id IS NULL` dentro de una ventana de 24h (configurable).
- Clave de cluster (`cluster_key`): SHA-256 de `id:url_hash` ordenados → idempotencia absoluta.
- Crea `Event` con campos denormalizados del article líder: `title`, `summary`, `importance`, `category`, `relevance`, `tags`.
- `relevance_score` calculado vía `Event::scoreFor(relevance, unique_sources)`.
- Solo modifica artículos **sin event_id** (nunca reclustering).
- `WithoutOverlapping('article-clustering')` + `lockForUpdate()` para concurrencia segura.
- `UniqueConstraintViolationException` se maneja silenciosamente (otra instancia ya creó el mismo cluster).

### 3. Migraciones
- `add_analysis_execution_metadata_to_articles_table`: columnas `analysis_started_at`, `analysis_completed_at`, `analysis_run_id`, `analysis_attempts`, `analysis_error`.
- `add_cluster_key_to_events_table`: columna `cluster_key` (unique index) para idempotencia del clustering.

---

## Tests

| Suite | Archivo | Resultado |
|---|---|---|
| Análisis | `tests/Feature/AnalyzeArticleJobTest.php` | 11 tests |
| Clustering | `tests/Feature/ClusterArticlesJobTest.php` | 30 tests |
| Deduplicación | `tests/Feature/ArticleDeduplicationTest.php` | 2 tests |
| Parser | `tests/Unit/AnalysisResponseParserTest.php` | existente, actualizado |

Suite completa antes del commit de la rama de análisis: **155 tests / 326 assertions**.

---

## Decisiones arquitectónicas clave

1. **Análisis 1:1 MVP**: Un solo `AnalyzeArticleJob` por artículo. Lease con `run_id` para evitar carreras sin Distributed Lock.
2. **`raw_response` siempre**: Se persiste la respuesta cruda del LLM para auditoría y debugging, incluso si el parseo falla parcialmente.
3. **URLs http/https sin credenciales**: `CanonicalUrl` normaliza sin autenticar. No hay storage de tokens en URLs.
4. **Clustering aditivo**: Solo procesa artículos sin `event_id`. Nunca reclusters eventos existentes. La coherencia de `events.tags` y aggregates se mantiene al crear, no al actualizar.
5. **`cluster_key`**: Determinista (SHA-256 de miembros ordenados). Permite re-ejecutar el job sin duplicar eventos.
6. **Eventos publicados intocables**: `Event` una vez creado no se modifica por clustering futuro. Los campos denormalizados quedan fijos al momento de creación.
7. **Config centralizada**: Todo configurable vía `config/newsscraper.php` + env vars. Sin hardcodes.

---

## Riesgos y workarounds pendientes

| Riesgo | Estado |
|---|---|
| `roach-php/laravel` incompatible con Laravel 13 | Pendiente decisión (ver PLAN.md §3.1). Alternativa B: Symfony crawler + HTTP client manual |
| Scraping no implementado | No hay `ScrapeSourceJob` ni spiders aún. Solo funciona con datos sembrados |
| `GenerateBriefingJob` no existe | Es el siguiente paso concreto |
| Scheduler no configurado | Falta `routes/console.php` con pipeline programado 07:00/18:00 |
| `FetchMarketSnapshotsJob` pendiente | Yahoo Finance API no integrada |
| Layout Blade: falta `@fonts` y revisión móvil/oscuro | Ver PLAN.md §2 (responsable Vicente) |

---

## Próximo objetivo: GenerateBriefingJob

**Criterios concretos:**
1. Crear `app/Jobs/GenerateBriefingJob.php` que:
   - Recibe `BriefingEdition` (morning/evening) como parámetro.
   - Selecciona `Event` del período (desde último briefing de la misma edición) con `relevance_score` >= umbral (`config('newsscraper.relevance.minimum_for_briefing')`).
   - Limita a N eventos (`config('newsscraper.briefing.events_per_edition')`, default 7).
   - Crea `Briefing` + sincroniza `briefing_event` con `position` (orden por `relevance_score DESC`).
   - Usa `Briefing::scopePublished()` para que la portada no muestre ediciones futuras.

2. Tests: `php artisan make:test --pest GenerateBriefingJobTest`
   - Test happy path: genera briefing con eventos ordenados.
   - Test sin eventos suficientes: crea briefing vacío o skip.
   - Test idempotencia: no duplica briefing para misma edición+fecha.
   - Test scope `published`: no muestra briefing con `published_at > now()`.

3. Después de GenerateBriefingJob:
   - **Scraping**: decidir entre opción A (roach-php/core) o B (Symfony crawler). Crear `ScrapeSourceJob` + al menos un spider.
   - **Pipeline orquestador**: `php artisan news:pipeline {--edition=}` que encadena scrape → analyze → cluster → briefing.
   - **Scheduler**: configurar en `routes/console.php`.

---

## Comandos para retomar

```bash
# Verificar entorno
php --version  # Requiere 8.4+

# Cambiar a la rama más reciente
git checkout feature/article-clustering-persistence

# Verificar que todo pasa
php artisan test --compact

# Verificar solo los tests nuevos de clustering
php artisan test --compact --filter=ClusterArticlesJob
php artisan test --compact --filter=AnalyzeArticleJob

# Correr pint sobre archivos modificados
vendor/bin/pint --dirty --format agent

# Verificar estado de ramas
git branch -vv
git log --oneline -5
```

---

## Convenciones del proyecto

- **Testing**: Pest v5 (`php artisan make:test --pest NameTest`). Tests feature por defecto.
- **PHP**: 8.4, constructor property promotion, return types explícitos, sin comentarios inline salvo lógica compleja.
- **Formato**: `vendor/bin/pint --dirty --format agent` antes de cada commit.
- **Modelos**: Usar factories en tests, nunca crear modelos manualmente sin aprobación.
- **Config**: Todo vía `config/newsscraper.php`, sin `env()` directo en código de dominio.
- **Archivos**: No crear docs sin explícito pedido del usuario (excepto este handoff).
