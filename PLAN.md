# PLAN.md — Plan de implementación de NewsScraper

Plan de trabajo para el proyecto integrador de la Unidad 3 (Laravel), IIP323W · 2026-2.
Contexto, decisiones y reglas están en `CLAUDE.md`; el alcance viene de `NewScrapper-propuesta-laravel.pdf`.

**Estado actual (2026-08-13):** el pipeline corre de punta a punta **con datos reales**. Las doce tablas, los cinco jobs, el orquestador `news:pipeline` y el scheduler de 07:00/18:00 están implementados; las seis rutas leen de la base de datos; los tres spiders recolectan de fuentes vivas; la capa de IA analiza con Ollama local y cadena de respaldo a OpenRouter; y la agrupación entre medios **ya funciona**. 279 tests, 595 assertions, todos en verde y ninguno toca la red (`Http::preventStrayRequests()` en toda la suite de Feature).

En la base de trabajo hoy: 59 artículos de 3 fuentes, 45 analizados, 20 acontecimientos, 2 briefings, 6 instrumentos de mercado.

**Lo que falta** es pulido y cierre: recuperar los 13 análisis fallidos, decidir la política de respaldo ante JSON inválido (§4.1), vigilar la sobre-agrupación (§4.2), la revisión visual que firma Vicente, el README y el guion de la demo.

> **Aviso de merge.** Las secciones §4.1 y §4.2 de este archivo fueron revertidas por error en el merge del commit `157dbab` y perdieron el trabajo de la cadena de respaldo y el diagnóstico de agrupación. Están restauradas y puestas al día acá. Al resolver conflictos en `PLAN.md`, revisa que no se pierdan bloques enteros.

> **Bloqueo de entorno:** el `composer.lock` fue resuelto en PHP 8.4+ (`symfony/*` 8.1 pide `php >=8.4.1`, y `pestphp/pest ^5` pide `php ^8.4` con PHPUnit 13). **El proyecto no corre en PHP 8.3.** Cada integrante necesita PHP 8.4 o 8.5 instalado; con 8.3 el `composer install` falla en la resolución.

---

## 0. Arquitectura en una frase

Un **pipeline por lotes** que corre dos veces al día:

```
Scheduler ──> ScrapeSourceJob (1 por Source, vía SafeHttpFetcher)
                 └─> Article (crudo, deduplicado por hash de URL)
                       └─> AnalyzeArticleJob (LLM → JSON validado)
                             └─> Analysis + Entities + Tags
                                   └─> ClusterArticlesJob (agrupa Articles → Event)
                                         └─> GenerateBriefingJob (top N Events → Briefing)
                                               └─> Blade lee de la BD (sin llamadas externas en request)

              FetchMarketSnapshotsJob (Yahoo Finance) ──> MarketSnapshot
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
- [ ] Layout Blade base + Tailwind funcionando. *(el layout y los tokens están, y `@fonts` ya se emite en `resources/views/components/layouts/app.blade.php` —antes solo lo hacía el `welcome.blade.php` que se eliminó, así que Archivo nunca cargaba—; falta la revisión móvil y de modo oscuro, que firma Vicente)*

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

> **El resto del pipeline ya no espera esta decisión.** El contrato
> `App\Contracts\SourceScraper` aísla la extracción: un spider recibe una `Source`
> y devuelve `list<ScrapedArticle>`. `ScrapeSourceJob` habla solo con ese
> contrato, así que elegir A o B cambia las implementaciones, no el pipeline.
> `SourceScraperResolver` construye el spider desde `sources.spider_class`; una
> fuente sin spider configurado se trata como fuente caída, que es exactamente el
> estado en que están hoy las cinco.

**Decisión tomada y ejecutada: opción B.** Nada de Roach. `SafeHttpFetcher` sobre el HTTP client de Laravel; RSS con `SimpleXMLElement` (parte de PHP) y HTML con `symfony/dom-crawler` v8.1.1 + `symfony/css-selector` v8.1.0, **ya instaladas y en el `composer.lock`**. No degradan nada: Symfony 8.1 ya estaba en el proyecto y ambas piden `php >=8.4.1`, igual que el resto del lock.

> **Al hacer `git pull`, corre `composer install`.** El lock cambió; sin eso, `HtmlListingSpider` falla con "class not found".

- [x] Respetar `robots.txt`, retardo entre requests y User-Agent propio, más allowlist de hosts, anti-SSRF, redirecciones revalidadas en cada salto, tamaño máximo y normalización de codificación. Todo centralizado en `SafeHttpFetcher`: ningún spider hace requests por su cuenta.
- [x] `DiarioFinancieroSpider` y `PulsoSpider` sobre `App\Spiders\HtmlListingSpider`, más `BbcBusinessSpider` sobre `RssSpider`. Verificadas en vivo; en la base actual: 11, 23 y 25 artículos reales.
- [x] **Cambiada la fuente de BBC.** `BbcMundoEconomiaSpider` se reemplazó por `BbcBusinessSpider` (`feeds.bbci.co.uk/news/business/rss.xml`). El feed anterior no era de economía: `feeds.bbci.co.uk/mundo/economia/rss.xml` devuelve el feed general de BBC Mundo —su `<title>` es "BBC Mundo"— y metía terremotos, eclipses y perfiles biográficos en un briefing financiero. **Contrapartida asumida:** el feed nuevo publica en inglés y con foco en Reino Unido; el resumen sale traducido porque el análisis se pide en español, pero la cobertura no es chilena. Es fuente de contraste, no del MVP.
- [x] **Fecha real de publicación.** Los listados chilenos casi no la publican —medido el 2026-08-13: Diario Financiero 12 de 103 tarjetas, Pulso 0 de 77— y sin ella todos los artículos de una corrida quedaban con la hora del scrape. Como la ventana de agrupación y el corte del briefing se miden contra `published_at`, eso no era cosmético. `HtmlListingSpider::enrich()` abre cada nota **después de aplicar el tope** y lee solo fecha y autor (`article:published_time`, `datePublished`, JSON-LD): nunca el cuerpo, la bajada sigue siendo la del listado, y si la nota no responde se conserva el artículo tal cual. Se apaga con `NEWS_SCRAPE_FETCH_METADATA=false`. **Resultado: 59 de 59 artículos con fecha real.**
- [x] Poblar `sources.spider_class` en `SourceSeeder`. Las fuentes sin araña quedan **inactivas** con el motivo escrito: una fuente activa sin spider solo acumula fallos.
- [x] Filtrar lo que no es periodismo. Los listados mezclan notas con publirreportajes, contenido *branded* y videos; cada araña declara en `articlePathPattern()` qué rutas son notas. Publicar publicidad pagada dentro del briefing, resumida por IA, sería engañar al lector.
- [ ] Arañas para Bloomberg Línea y El Mercurio Inversiones.

> **Ninguna fuente chilena publica RSS.** Se probaron Diario Financiero, Pulso, Emol,
> BioBioChile y El Mostrador: responden 404, redirigen a la portada o devuelven HTML. Por eso
> las dos chilenas se leen del listado de la portada de sección —un solo request, sin abrir la
> nota, sin tocar paywalls— y BBC Mundo, la única con RSS servible, quedó como respaldo. Ojo con
> esa última: su feed `/economia` sirve contenido general, no solo económico.

> **Volumen bajo en Diario Financiero.** Solo 6 de sus ~120 tarjetas quedan tras filtrar por
> sección y exigir bajada. Alcanza para el MVP, pero si hace falta más habrá que leer también
> `/empresas` y `/economia-y-politica` como listados aparte.
- [x] `ScrapeSourceJob`: corre un spider, normaliza URLs, persiste con `updateOrCreate` sobre `url_hash`, marca `analysis_status = pending`.
- [x] Manejo de fallos por fuente: try/catch por Source, incrementar `failure_count`, log estructurado, **nunca** tumbar el lote completo. El job no relanza nunca: un reintento de la cola volvería a golpear una fuente que ya sabemos caída.
- [x] Comando `php artisan news:scrape {--source=} {--spider=} {--sync}` para correr manualmente.

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
- [x] **Nada se filtra antes de su hora de publicación.** `/briefings/{id}` responde 404 si la edición todavía no se publicó, y un acontecimiento es público solo si alguna edición ya publicada lo incluye — criterio aplicado en el detalle, en las categorías, en los relacionados y en el contador de la barra de categorías. Sin esto, el contenido de la edición de la tarde era accesible por URL desde la mañana.
- [x] Reemplazar `DemoContent` por consultas Eloquent con eager loading una vez existan los modelos. `Model::preventLazyLoading()` está activo fuera de producción, así que un eager load faltante rompe los tests en vez de esconderse como N+1.

**Hecho cuando:** todas las rutas renderizan con datos sembrados **desde la base de datos** (no de demostración) y hay feature tests de smoke por cada una. ✔

---

## 4. Semana 3 — IA y funcionalidad principal (responsables: Bruno, Vicente)

### 4.1 Capa de IA (Bruno)
- [x] `App\Contracts\NewsAnalyzer` con `analyze(NewsArticleInput $article): AnalysisResult`. Recibe un DTO, no el modelo: el analizador no toca la base de datos.
- [x] `App\Services\Ai\OllamaAnalyzer` usando el HTTP client de Laravel con timeout y `retry()`.
- [x] `AnalysisResult` como DTO tipado (resumen, categoría, relevancia, empresas, personas, tags, explicación).
- [x] Prompt en `resources/views/prompts/analyze-article-v1.blade.php` (no en `resources/prompts/`, para que `view()` lo encuentre): pide JSON estricto, en español, con categorías del enum.
- [x] Validación de la respuesta con `Validator` contra un esquema; en fallo → 1 reintento → `AnalysisStatus::Failed` + log. `raw_response` viaja en el propio `AnalysisResult` y se guarda siempre.
- [x] `AnalyzeArticleJob` con `ThrottlesExceptions` / backoff; entidades y tags con `firstOrCreate` normalizado ("Codelco" y "CODELCO" son la misma fila).
- [x] Binding del driver en `AppServiceProvider` según `config('newsscraper.ai.driver')`.
- [x] **Cadena de respaldo entre modelos** (`NEWS_AI_DRIVER=chain`). `FallbackNewsAnalyzer` prueba los eslabones de `ai.chain` en orden y usa el primero que responda; es un `NewsAnalyzer` más, así que el pipeline no se entera. Incluye `OpenRouterAnalyzer` (API compatible con OpenAI, modelos gratuitos) y un cortocircuito por modelo.
- [x] Comando `php artisan news:analyze {--source=} {--retry-failed} {--only-failed} {--limit=}`. Es la **única** forma de recuperar un análisis fallido: `news:pipeline` solo toma los `pending`, y re-scrapear tampoco reencola —un artículo que ya existe y no está `pending` se salta a propósito, para no repetir llamadas que ya se pagaron—.

**Reglas de la cadena, y por qué:**

- Los eslabones se construyen **perezosamente**. Los constructores validan y lanzan si falta configuración, así que construirlos todos por adelantado haría que un eslabón sin API key tumbara también a los que sí funcionan.
- Solo se cambia de modelo ante **indisponibilidad** (`App\Contracts\AnalyzerUnavailable`): timeout, 429, 503, configuración ausente. Una respuesta mal formada no dispara el salto —es casi siempre el prompt— salvo que se active `NEWS_AI_FALLBACK_ON_INVALID`.
- **Cortocircuito por modelo.** Sin él, un proveedor caído se reintenta una vez por artículo: con 40 artículos y 60 s de timeout son 40 minutos de espera pura.
- Si se agota la cadena, el artículo vuelve a `pending`, **no** a `failed`: una caída del proveedor no puede sacarlo del pipeline para siempre.
- `analyses.provider` y `analyses.model` registran qué modelo respondió cada artículo.

> **La oferta gratuita de OpenRouter rota.** Los modelos de `ai.chain` fueron verificados contra
> `https://openrouter.ai/api/v1/models` y soportan salida estructurada, pero hay que revisarlos
> cada cierto tiempo. Sus cuotas son bajas: el 429 es esperable y es justo lo que la cadena resuelve.

> ### 🟠 Decisión abierta — un modelo local chico no baja al respaldo
>
> Con Ollama ya instalado, **los 45 análisis de la base los resolvió `ollama llama3.2:3b`**, el
> primer eslabón, y la cadena casi no se ejerció. Lo que apareció en su lugar es otro problema: el
> log acumula **25 `AnalysisValidationException`**. Un modelo de 3B respeta el `format` la mayoría
> de las veces, pero no siempre, y como el JSON inválido **no** cuenta como indisponibilidad, esos
> artículos van directo a `failed` sin llegar a probar los tres modelos de OpenRouter que están
> justo debajo. Hoy hay **13 artículos `failed`** por esa vía, más 1 colgado en `processing`.
>
> No es un bug: el código hace lo documentado. Cambió la premisa. La regla se escribió asumiendo
> que el JSON inválido delata un prompt roto; acá delata un modelo demasiado chico.
>
> | Salida | Costo |
> |---|---|
> | `NEWS_AI_FALLBACK_ON_INVALID=true` | Vuelve a esconder los errores de prompt, que es lo que la regla evitaba |
> | Un modelo local más grande (`llama3.1:8b` o similar) | Depende de la máquina de cada integrante |
> | Poner OpenRouter primero en la cadena | Gasta cuota gratuita en lo que Ollama ya resuelve bien |
> | Distinguir "esquema fallado N veces seguidas" de "fallo puntual" | Más código en `FallbackNewsAnalyzer` |
>
> Mientras se decide: `php artisan news:analyze --retry-failed` los recupera.

**Concurrencia del análisis.** `AnalyzeArticleJob` toma un *lease* sobre el artículo (`analysis_status = processing` + `analysis_run_id`) antes de llamar al modelo, y solo persiste si al cerrar sigue teniendo el lease. Un `processing` más viejo que `NEWS_AI_PROCESSING_STALE_AFTER` se considera abandonado y se puede retomar. Un artículo sin texto y una URL en HTTP se resuelven antes de gastar una llamada al LLM.

> **Decisión abierta — política de reintentos.** Hoy cualquier fallo reintenta 4 veces con backoff y recién ahí queda `failed`. `CLAUDE.md §4` dice "se reintenta una vez y luego se marca failed". Para una respuesta mal formada, reintentar cuatro veces gasta cuatro llamadas al modelo por algo que probablemente vuelva a fallar igual; para Ollama caído, en cambio, cuatro reintentos están bien. Conviene decidir si se distingue el tipo de fallo o si se corrige la regla de `CLAUDE.md`.

### 4.2 Agrupación y priorización (Bruno + Vicente)
- [x] `ClusterArticlesJob`: dentro de la ventana configurable, agrupa por (a) similitud de títulos normalizados, (b) entidades compartidas, (c) misma categoría.
- [x] Crear o reutilizar `Event`; `relevance_score` = relevancia máxima del cluster + bonus por fuentes distintas.
- [x] `GenerateBriefingJob`: toma los Events del período (desde el briefing anterior), ordena por `relevance_score`, corta en N (config, por defecto 7) y crea `Briefing` + pivote ordenado. Una edición vacía no se publica.

- [x] **Arreglada la deduplicación entre medios.** Ver el bloque verde de abajo.

**La identidad de un `Event` es `cluster_key`**, el hash del conjunto exacto de artículos que lo forman (ids + `url_hash`). El slug lleva ese hash como sufijo, así que dos hechos distintos con el mismo titular nunca colisionan, y reprocesar el mismo grupo no duplica nada.

> ### ✅ La deduplicación entre medios ya funciona
>
> **El problema, medido el 2026-08-13** sobre 18 artículos reales de Diario Financiero y Pulso
> analizados con modelos de verdad: dos medios cubrieron la misma jornada del caso Sartor y
> quedaron como **dos acontecimientos separados**.
>
> | Medio | Titular |
> |---|---|
> | Diario Financiero | "**Formalización del caso Sartor**: Fiscalía responde a defensas y vincula…" |
> | Pulso | "**Caso Sartor**: Fiscalía cuestiona defensa de imputados en décima jornada" |
>
> Las dos condiciones fallaban. **Jaccard de títulos 0,25** contra un umbral de 0,62: dos medios
> redactan el mismo hecho con vocabulario distinto, y los titulares en español comparten muchos
> menos tokens de los que asumía el umbral. Y **entidades compartidas 0**, que era la causa real:
> un artículo extrajo `larrain` y el otro `pedro pablo larrain` —la misma persona— que
> `EntityNormalizer` no unía porque comparaba cadenas exactas.
>
> **Lo que se hizo**, atacando la causa y no el síntoma:
>
> 1. **Coincidencia laxa en `EntityNormalizer`.** `matches()` acepta que una mención sea subconjunto
>    estricto de otra, siempre que aporte al menos un token distintivo: que no esté en
>    `GENERIC_TOKENS` (`banco`, `ministerio`, `fiscalia`, `corte`, `presidente`, `nacional`,
>    `estado`…) y que llegue a 4 caracteres. Sin esa condición, `banco` habría unido `Banco Central`
>    con `Banco Estado` y de ahí en cascada dos hechos que solo comparten vocabulario financiero.
>    `mostSpecific()` conserva la variante completa, así que en el `Event` queda `pedro pablo
>    larrain`, no `larrain`.
> 2. **`shared_entities_minimum` de 2 a 1**, manteniendo el Jaccard alto en 0,62: las entidades
>    mandan y el título queda como refuerzo, no como requisito. Bajar el umbral de títulos se
>    evaluó y se descartó — solo agrupaba por debajo de 0,25, y a ese nivel se fusionan hechos
>    distintos que comparten jerga financiera.
> 3. **`ArticleClusterer::groupMemberEntities()`** cuenta por artículo distinto, no por mención: un
>    solo artículo que nombre a la persona de dos formas no puede figurar como si dos medios la
>    hubieran mencionado.
>
> **Comprobado.** Cinco tests nuevos en `tests/Unit/ArticleClusteringEngineTest.php` fijan el caso
> Sartor, los límites (`Banco`/`Banco Central` **no** une, `BHP`/`BHP Billiton` tampoco por corta),
> y que una entidad coincidente no pueda saltarse la ventana temporal ni la categoría. Y con datos
> reales: la base tiene un acontecimiento que une **3 artículos de 2 medios** sobre la venta del 1%
> de Falabella por las hermanas Cúneo (Pulso ×2 + Diario Financiero). Es el punto 3 del alcance del
> MVP, funcionando por primera vez.

> ### 🟠 Decisión abierta — sobre-agrupación
>
> La contrapartida de `shared_entities_minimum = 1`: compartir **una sola** empresa basta. En ese
> mismo acontecimiento de Falabella entró una nota sobre la **dotación de Falabella y Cencosud**,
> que es un hecho distinto y solo comparte la empresa y la categoría.
>
> `GENERIC_TOKENS` acota el problema para instituciones, pero el nombre propio de una empresa
> grande sigue uniendo mucho. Antes de tocar nada conviene medirlo con más corridas: con 20
> acontecimientos no alcanza para saber si es un caso aislado o el patrón. Si resulta ser el
> patrón, las salidas son exigir 2 entidades **o** un Jaccard mínimo de refuerzo (hoy la condición
> es un `O`, podría ser `entidades >= 1 Y jaccard >= algo bajo`).

> **Decisión abierta — artículos que llegan tarde.** Solo se agrupan artículos con `event_id` nulo, y un grupo con un miembro nuevo tiene otro `cluster_key`. Consecuencia: si un medio publica su versión de una historia después de que el acontecimiento ya se creó, se abre un **segundo** acontecimiento sobre el mismo hecho en vez de sumarse al primero. Eso choca de frente con el punto 3 del alcance del MVP ("agrupación de artículos que hablan del mismo acontecimiento"), que es lo que justifica el producto. La alternativa —reutilizar el acontecimiento al que ya pertenece algún miembro— cuesta la inmutabilidad que hoy hace al job seguro ante concurrencia. Hay que elegir.

> **Decisión abierta — ventana del briefing.** El período va desde la edición **anterior de la misma edición**, o sea 24 h: la ventana de la mañana y la de la tarde se solapan y un mismo acontecimiento puede salir en las dos del mismo día. Si la intención es que la edición de la tarde traiga solo lo nuevo desde la mañana, el corte debe ser contra el briefing anterior sin filtrar por edición.

### 4.3 Automatización
- [x] Scheduler en `routes/console.php`: pipeline a las **07:00** (`morning`) y **18:00** (`evening`), zona `America/Santiago`, con `withoutOverlapping()` y `onOneServer()`. Los horarios salen de `BriefingEdition::scheduledHour()`, no escritos a mano.
- [x] Comando orquestador `php artisan news:pipeline {--edition=} {--spider=} {--skip-scrape}` que encadena scrape → analyze → cluster → briefing. Las cuatro etapas corren **en orden y en el proceso del comando**: agrupar antes de que terminen los análisis produciría acontecimientos incompletos.
- [x] `FetchMarketSnapshotsJob` para Yahoo Finance, detrás de `App\Contracts\MarketDataProvider`. Comando manual: `php artisan news:markets`. **Sin API key:** el endpoint `/v8/finance/chart` es abierto; lo único que exige es un User-Agent declarado, y usa el mismo identificable del scraping.

> **`^IPSA` viene congelado desde Yahoo.** Su última hora de mercado es del
> 2026-07-17, así que llega con un solo cierre y variación 0,00%. Los otros cinco
> instrumentos responden al día. Alternativa verificada: `ECH` (iShares MSCI Chile
> ETF), que cotiza en NY y sí está al día — pero es un proxy, no el índice. Es una
> decisión de producto pendiente, no un bug del código.

> **No se usa `scheb/yahoo-finance-api`.** Sigue declarado en `composer.json` pero
> ninguna clase lo importa. Se prefirió el HTTP client de Laravel por tres razones:
> el endpoint de gráficos devuelve precio, cierre anterior e histórico en **una**
> llamada (el paquete necesita dos por símbolo), no exige la danza de cookie +
> crumb que el paquete implementa para `/v7/finance/quote`, y `Http::fake()`
> intercepta las llamadas —el paquete usa Guzzle directo, así que los tests
> tendrían que mockear su cliente. Queda por decidir si se saca la dependencia.

**Hecho cuando:** `php artisan news:pipeline --edition=morning` genera un briefing real end-to-end. ✔ Probado en `tests/Feature/NewsPipelineCommandTest.php` con spider y analizador de mentira, **y repetido con fuentes y modelos reales**: 59 artículos de 3 medios, 45 analizados, 20 acontecimientos —uno de ellos con 2 medios distintos— y 2 briefings publicados.

---

## 5. Semana 4 — Cierre, pruebas y presentación (todo el equipo)

- [x] Cobertura de tests: parseo del LLM (JSON válido, inválido, campos faltantes), clustering, deduplicación por `url_hash`, orden del briefing, smoke de las 6 rutas y el pipeline completo. Ninguno toca la red.
- [ ] Estados vacíos y de error en la UI (sin briefing aún, fuente caída, análisis fallido). *(los tres primeros hechos)*
- [x] Seeder de demo (`DemoSeeder`) con un par de briefings realistas, por si el scraping falla en vivo durante la presentación. **Plan B obligatorio.** Adelantado a la Semana 1: 13 acontecimientos, 5 ediciones y 6 instrumentos, con fechas relativas a hoy.
- [x] **`DemoSeeder` sacado de `DatabaseSeeder`.** Mientras estuvo ahí, un `migrate:fresh --seed` llenaba la base de acontecimientos inventados y en la portada era imposible distinguir qué venía del scraping real y qué era relleno. Ahora `--seed` siembra solo las fuentes y la demo se corre a mano: `php artisan db:seed --class=DemoSeeder`.
- [x] Resuelto el tema del proveedor de IA: **no hay requisito de Gemini**. La capa admite Ollama local y OpenRouter (modelos gratuitos), y encadena ambos. Agregar un `GeminiAnalyzer` seguiría siendo una clase más si alguna vez hace falta.
- [x] Validada la cadena con una API key real de OpenRouter, sobre 18 artículos chilenos reales (2026-08-13). **Los tres modelos rotaron solos**: `gemma` 15, `gpt-oss` 2, `nemotron` 1 — gemma agotó su cuota gratuita y el respaldo entró sin intervención. Ollama falló las 18 veces (todavía no estaba instalado) y el cortocircuito lo fue salteando. ~19 s por artículo, 18 de 18 analizados.
- [x] Calidad del análisis confirmada: con el analizador falso todo caía en `economy`; con modelos reales las categorías salen variadas y correctas (`regulation`, `companies`, `markets`, `monetary`, `commodities`) y las entidades bien extraídas.
- [x] **Arreglada la deduplicación entre medios** — ver §4.2. Era el corazón del MVP y lo que la validación con IA real dejó al descubierto.
- [x] `NEWS_MIN_RELEVANCE=medium` fijado en `phpunit.xml`. Sin eso la suite hereda el `.env` de cada quien, y un `.env` viejo con `"media"` (el enum pasó a inglés) tumbaba diez tests en la máquina de un integrante y en ninguna otra.
- [x] `@fonts` emitido en `resources/views/components/layouts/app.blade.php`. Archivo se sirve desde Bunny y sin esa directiva el manifiesto nunca salía: toda la app caía al fallback del sistema.
- [ ] **Recuperar los 13 análisis fallidos** (`php artisan news:analyze --retry-failed`) y el artículo colgado en `processing`. Depende de resolver antes la decisión de §4.1, o volverán a fallar igual.
- [ ] Reanalizar los 25 artículos de BBC si se quiere homogeneidad de modelo (consume cuota).
- [ ] `vendor/bin/pint` sobre todo el proyecto.
- [ ] README actualizado: la tabla de arañas todavía dice `BbcMundoEconomiaSpider`.
- [ ] Guion de la demo: mostrar briefing → **abrir el acontecimiento de Falabella, que tiene 2 medios** → mostrar el enlace original → gráficos de mercado.

---

## 6. Riesgos y mitigaciones

| Riesgo (propuesta §10) | Mitigación en el plan |
|---|---|
| Cambios de HTML rompen scrapers | Spiders aislados por fuente + fixtures en tests que detectan el quiebre |
| Bloqueo de scraping / paywalls | Fuente se marca inactiva, se registra el fallo; no se elude nada. `enrich()` nunca descarta un artículo porque la nota no responda |
| Alucinaciones de la IA | Validación de esquema, `raw_response` guardado, enlace al original siempre visible, aviso de contenido generado |
| Clasificación incorrecta | Categorías cerradas por enum; relevancia acotada 1–4 |
| Duplicados no detectados | Clustering por título + entidades **con coincidencia laxa de nombres** + ventana temporal; umbrales ajustables sin tocar código |
| Caída de un modelo de IA | Cadena de respaldo (`NEWS_AI_DRIVER=chain`) + cortocircuito por modelo; si se agota, el artículo vuelve a `pending`, no a `failed` |
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
