<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v5
- phpunit/phpunit (PHPUNIT) - v13
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>

---

# NewsScraper — Parámetros de trabajo del proyecto

> Todo lo que está encima de esta línea lo regenera `php artisan boost:update` (bloque `<laravel-boost-guidelines>`). **Nunca edites ese bloque a mano.** Esta sección es el contexto propio del proyecto y sí se edita a mano.
> `AGENTS.md` es una copia del bloque de Boost para otros agentes; si cambias algo aquí abajo que también deba verlo otro agente, replícalo allá manualmente.

## 1. Contexto

- **Producto:** NewsScraper — agente de coyuntura financiera. Recolecta noticias financieras de varias fuentes, las analiza con un LLM y publica **dos briefings diarios** (mañana y tarde) con los eventos económicos más relevantes.
- **Curso:** IIP323W — Tecnologías y Aplicaciones Web y Móviles · Sección 1 · Semestre 2026-2 · Profesor Cristóbal Maturana Ahumada.
- **Entrega:** Proyecto integrador de la Unidad 3 (Laravel), 4 semanas.
- **Equipo:** Bruno Caro (AI Engineer) · Vicente Ramírez (Frontend) · Joaquín Parraud (Full-stack / backend).
- **Referencia conceptual:** [Agentes_de_Coyuntura](https://github.com/DataMarketAnalysisClub/Agentes_de_Coyuntura).
- **Fuente de verdad del alcance:** `NewScrapper-propuesta-laravel.pdf` y `PLAN.md`. Si una tarea contradice la propuesta, dilo antes de implementar.

**Usuarios objetivo:** estudiante universitario de ingeniería/economía (perfil "Juan González", 21 años) y ejecutivo con poco tiempo (perfil "Federico Valdés"). Ambos quieren **leer poco y entender rápido**: el briefing debe ser escaneable en 2–3 minutos.

## 2. Alcance del MVP (no ampliar sin pedirlo)

1. Recolección automatizada de noticias desde fuentes configuradas (Diario Financiero, Bloomberg u otras accesibles), 2 veces al día.
2. Análisis por LLM que devuelve **JSON estructurado**: resumen, categoría, nivel de relevancia, empresas mencionadas, personas mencionadas, etiquetas, explicación de la importancia económica.
3. Clasificación, priorización y **agrupación de artículos que hablan del mismo acontecimiento** (deduplicación entre medios).
4. Briefing AM/PM con: título, fuente(s), fecha y hora, resumen, categoría, relevancia, empresas y personas, explicación de importancia y enlace al original.
5. Datos de mercado (Yahoo Finance) + gráficos como apoyo visual del briefing.

**Fuera del MVP:** cuentas de usuario y personalización, notificaciones por correo/push, app móvil, búsqueda full-text avanzada, multi-idioma, panel de administración completo. Si algo de esto parece necesario, propónlo primero.

## 3. Decisiones técnicas fijadas

| Área | Decisión |
|---|---|
| Backend | Laravel 13 + **PHP 8.4 o superior**. `composer.json` dice `^8.3`, pero es letra muerta: el `composer.lock` resuelto trae `symfony/*` 8.1 (`php >=8.4.1`) y `pestphp/pest ^5` (`php ^8.4`, PHPUnit 13). **En PHP 8.3 el `composer install` falla.** |
| Base de datos | SQLite (`database/database.sqlite`) |
| Scraping | ⚠️ **Sin resolver.** [Roach PHP](https://roach-php.dev/docs/introduction) era la decisión, pero `roach-php/laravel` no soporta Laravel 13. Alternativas y criterio de decisión en `PLAN.md §3.1` |
| LLM | Ollama Cloud vía HTTP (`OLLAMA_API_URL`, `OLLAMA_MODEL`, `OLLAMA_API_KEY`) |
| Datos de mercado | [scheb/yahoo-finance-api](https://github.com/scheb/yahoo-finance-api) (no oficial) |
| Gráficos | SVG en línea con los tokens `--color-series-*` (`<x-sparkline>`). `laravel-charts` se descartó: última versión de 2023 y sin restricciones declaradas en su `composer.json` |
| Frontend | Blade + Tailwind CSS 4 + Vite (sin SPA, sin framework JS) |
| Colas / scheduling | `QUEUE_CONNECTION=database` + Laravel Scheduler |
| Tests | Pest 5 |
| Formato | Laravel Pint |

**Discrepancia conocida y sin resolver:** la pauta del curso en la sección 7 de la propuesta dice *"El proyecto debe integrar una IA con la API de Google Gemini (plan gratis)"*, pero el equipo eligió Ollama y el `.env.example` ya está configurado para Ollama. **Por eso la capa de IA se implementa detrás de una interfaz (`Contracts\NewsAnalyzer`) con drivers intercambiables**, de modo que agregar un `GeminiAnalyzer` sea cambiar una variable de entorno y no reescribir el pipeline. Driver por defecto: Ollama. Antes de la entrega hay que confirmar con el profesor cuál corre en la demo.

## 4. Reglas de trabajo

### Dependencias
- `scheb/yahoo-finance-api` está aprobado y pendiente de instalar (verificado compatible: `php >=8.1`, sin dependencia de framework). Roach quedó bloqueado (ver `PLAN.md §3.1`) y laravel-charts se descartó. **Cada `composer require` / `npm install` nuevo se pregunta antes** (regla del bloque Boost).
- No cambiar versiones de Laravel, PHP ni Pest.

### Código
- **Identificadores en inglés** (modelos, métodos, columnas, rutas): `Article`, `BriefingEdition`, `relevance_level`. **Texto visible en español** (Blade, mensajes de validación, seeders de categorías). Los prompts al LLM van en español y piden respuesta en español.
- Un scraper por fuente, cada uno como Spider de Roach en `app/Spiders/`.
- Toda llamada externa (scraping, LLM, Yahoo) ocurre **dentro de un Job en cola**, nunca en un request HTTP. Los controllers solo leen de la base de datos.
- Los Jobs deben ser **idempotentes**: reprocesar el mismo artículo no debe duplicar filas (usar `updateOrCreate` sobre un hash de URL).
- Enums PHP para valores cerrados: `NewsCategory`, `RelevanceLevel`, `BriefingEdition`, `EntityType`.
- Zona horaria del dominio: `America/Santiago`. Guardar UTC, mostrar en horario de Chile.

### Trato de la salida del LLM
- El modelo **no es una fuente confiable**. Toda respuesta se valida contra un esquema antes de persistir; si no valida, se reintenta una vez y luego se marca el análisis como `failed` — nunca se guarda a medias.
- Guardar siempre la **respuesta cruda** (`raw_response`) junto con el análisis parseado, para poder depurar alucinaciones.
- La UI debe dejar claro que el resumen es generado por IA y siempre mostrar el **enlace a la publicación original**.

### Scraping responsable (requisito, no opcional)
- Respetar `robots.txt` y aplicar retardo entre requests (Roach: `$delay`), con User-Agent identificable.
- **No almacenar ni republicar el texto completo con copyright.** Se guarda lo mínimo para analizar; lo que se publica es el resumen generado + metadatos + enlace.
- Nunca eludir paywalls. Si una fuente bloquea o exige pago, se marca inactiva y se registra el fallo; no se buscan rodeos técnicos.
- Los fallos de una fuente no pueden voltear el pipeline: cada fuente falla de forma aislada.

### Seguridad y secretos
- `.env` nunca se commitea. Las claves solo se leen vía `config()`, jamás `env()` fuera de `config/`.
- Al agregar una variable nueva, actualizar `.env.example` con placeholder (sin espacios alrededor del `=`, ya nos pasó).

### Tests
- Pest, mayoría feature tests. `Http::fake()` para el LLM y Yahoo; fixtures HTML en `tests/Fixtures/` para los spiders. **Ningún test toca la red.**
- Mínimo cubierto: parseo de la respuesta del LLM (incluyendo JSON inválido), deduplicación de artículos, selección y orden del briefing, y que las vistas principales rendericen.

### Git
- Rama `main`. Commits en español, imperativos, con prefijo (`feat:`, `fix:`, `chore:`).
- Correr `vendor/bin/pint --dirty --format agent` antes de cerrar cualquier cambio en PHP.

## 5. Glosario del dominio (usar estos nombres en el código)

- **Source** — medio de origen (Diario Financiero, Bloomberg…).
- **Article** — publicación individual scrapeada de una Source.
- **Analysis** — salida estructurada del LLM para un Article.
- **Event** — acontecimiento único; agrupa varios Articles de distintas Sources que hablan de lo mismo. Es la unidad que se muestra en el briefing.
- **Briefing** — edición publicada (`morning` / `evening`) con los Events más relevantes del período.
- **Entity** — empresa o persona mencionada en un Article.

## 6. Comandos frecuentes

```bash
composer run setup     # instalación completa desde cero
composer run dev       # server + queue + logs + vite
composer run test      # tests
vendor/bin/pint        # formato
php artisan queue:work # procesar jobs del pipeline
```
