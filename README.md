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

> ### ⚠️ Después de cada `git pull`, corre `composer install`
>
> El `composer.lock` cambia cuando alguien agrega una librería. Si no lo corres,
> vas a ver errores de "class not found" en clases que en el repo sí existen.
> Lo mismo con `npm install` cuando cambie `package-lock.json`.

#### Diagnóstico rápido

Si algo no arranca, esta tabla dice qué correr. Sirve también para quien revise el proyecto sin
haberlo instalado antes.

| Síntoma | Causa | Comando |
|---|---|---|
| `Class "Symfony\Component\DomCrawler\Crawler" not found` | Falta `symfony/dom-crawler` | `composer install` |
| `Class "Symfony\Component\CssSelector\..." not found` | Falta `symfony/css-selector` | `composer install` |
| Cualquier `Class ... not found` de `vendor/` | `vendor/` desincronizado del lock | `composer install` |
| `Unable to locate file in Vite manifest` | Falta compilar el frontend | `npm install && npm run build` |
| `no such table: ...` | Faltan migraciones | `php artisan migrate --seed` |
| `composer run dev` termina con código 1 en Windows | `laravel/pail` necesita `pcntl` (POSIX) | `composer run dev:win` |
| `composer: command not found` en Git Bash | bash no completa `.bat` | `composer.bat ...` o usa PowerShell |

Para verificar de una que el entorno está completo:

```bash
composer install && php artisan test --compact
```

Si la suite pasa entera, no falta ninguna dependencia.

### Dependencias de terceros

Versiones exactas del `composer.lock`. Ninguna se instala a mano: las trae `composer install`.

| Paquete | Versión | Para qué | Agregada |
|---|---|---|---|
| `laravel/framework` | v13.23 | Framework | Inicio |
| `symfony/dom-crawler` | v8.1.1 | Leer los listados HTML de las fuentes sin RSS (`HtmlListingSpider`) | Semana 3 |
| `symfony/css-selector` | v8.1.0 | Selectores CSS para DomCrawler | Semana 3 |
| `scheb/yahoo-finance-api` | v5.2.0 | **Sin uso.** Ninguna clase la importa: los datos de mercado salen del HTTP client de Laravel. Pendiente decidir si se saca | Semana 1 |

`symfony/dom-crawler` y `symfony/css-selector` exigen **PHP >= 8.4.1**, igual que el resto del
lock. No agregan dependencias transitivas nuevas: Symfony 8.1 ya estaba en el proyecto.

**Lo que NO requirió dependencia nueva**, por si alguien va a buscarla y no la encuentra:

- **RSS** → `SimpleXMLElement`, parte de PHP (`ext-simplexml`).
- **OpenRouter y la cadena de respaldo entre modelos** → HTTP client de Laravel. No hay SDK de
  OpenAI ni de OpenRouter en el proyecto y no hace falta: la API es HTTP + JSON.
- **Datos de mercado** → HTTP client de Laravel contra el endpoint público de Yahoo.
- **Gráficos** → SVG en línea (`<x-sparkline>`), sin librería de charts.

Si al agregar una librería nueva: se documenta acá con versión exacta y para qué sirve, y se
avisa al equipo de correr `composer install`. Es regla del proyecto (`CLAUDE.md §4`).

Copia el archivo de entorno y genera la clave de la aplicación:

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

El `--seed` carga **solo las fuentes del MVP**. Las noticias las trae el pipeline real; para
llenar la aplicación, corre `php artisan news:pipeline`.

Si necesitas datos sin salir a la red —o si el scraping o la IA fallan en vivo durante la
presentación— está el plan B:

```bash
php artisan db:seed --class=DemoSeeder
```

Carga 13 acontecimientos, 5 ediciones y 6 instrumentos de mercado, con fechas relativas a hoy.
Es idempotente: se puede volver a correr sin duplicar nada. **No se siembra por defecto** para
que en la portada no se mezclen noticias reales con contenido inventado.

> **`migrate:fresh` borra los análisis.** Cada artículo analizado costó llamadas a la API, y
> `migrate:fresh` tira todas las tablas. Para cambios de esquema usa `php artisan migrate`. El
> pipeline nunca reanaliza lo que ya está `completed`, así que re-scrapear es gratis.

Alternativamente, `composer run setup` ejecuta estos pasos automáticamente.

### Configuración de la IA

El pipeline habla con `App\Contracts\NewsAnalyzer`, así que el proveedor se cambia con una
variable y sin tocar código. `NEWS_AI_DRIVER` acepta:

| Valor | Qué hace |
|---|---|
| `chain` | **Recomendado.** Prueba varios modelos en orden y usa el primero que responda |
| `ollama` | Solo Ollama local |
| `openrouter` | Solo OpenRouter |
| `fake` | Respuesta fija, sin red. Solo en `local` y `testing` |

#### Cadena de respaldo (`chain`)

Los eslabones se declaran en `config/newsscraper.php` (`ai.chain`): por defecto, Ollama local y
después tres modelos gratuitos de OpenRouter.

**Un eslabón mal configurado no rompe la cadena, se salta.** Puedes dejar Ollama primero aunque
no lo tengas instalado: falla al construirse y pasa al siguiente. Los analizadores se construyen
recién cuando les toca el turno.

Solo se cambia de modelo por **indisponibilidad** (timeout, 429, 503, falta de configuración).
Una respuesta que no cumple el esquema JSON **no** dispara el salto, porque casi siempre es un
problema del prompt y taparlo con otro modelo lo esconde. Se puede activar con
`NEWS_AI_FALLBACK_ON_INVALID=true`.

Hay un cortocircuito por modelo: tras `NEWS_AI_CIRCUIT_FAILURES` fallos seguidos se salta
durante `NEWS_AI_CIRCUIT_TTL` segundos. Sin él, un proveedor caído se reintentaría una vez por
artículo — con 40 artículos y 60 s de timeout, 40 minutos de espera pura.

Qué modelo respondió cada artículo queda en `analyses.provider` y `analyses.model`.

#### OpenRouter

API compatible con OpenAI, con modelos gratuitos. Consigue una clave en
[openrouter.ai/keys](https://openrouter.ai/keys) y ponla en tu `.env`:

```
OPENROUTER_API_KEY=sk-or-v1-...
```

> **La clave va solo en tu `.env`, que nunca se commitea.** No la pegues en el README, en un
> issue, en un chat ni en el `.env.example`. Sin ella, los eslabones `openrouter` de la cadena
> se saltan solos y el pipeline sigue funcionando con los demás.

Los modelos gratuitos de la cadena por defecto soportan salida estructurada, que es lo que hace
que el JSON cumpla el esquema. **La oferta gratuita de OpenRouter rota**: si un modelo deja de
existir, verifica en [openrouter.ai/models](https://openrouter.ai/models) y actualiza `ai.chain`.
También tienen cuota baja, así que el `429` es esperable — es justamente el caso que la cadena
resuelve.

#### Ollama

Requiere tenerlo instalado y corriendo. En `.env`:

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

La URL debe usar HTTP, un literal IP loopback (`127.0.0.1` o `[::1]`) y un puerto explícito:
`OllamaAnalyzer` rechaza cualquier otro host, así que Ollama Cloud no sirve por esta vía.

Para comprobar que está arriba y que el modelo existe:

```bash
ollama serve
ollama list          # si llama3.2:3b no aparece: ollama pull llama3.2:3b
```

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
php artisan news:analyze --limit=10           # analizar de a poco
php artisan news:analyze --retry-failed       # recuperar los análisis fallidos
php artisan news:markets                      # solo las cotizaciones
php artisan schedule:list                     # ver los horarios registrados
```

Las cuatro etapas del pipeline corren **en orden dentro del proceso del comando**, no repartidas
en la cola: agrupar antes de que terminen los análisis produciría acontecimientos incompletos.
`news:scrape`, en cambio, encola por defecto (usa `--sync` si no tienes un worker levantado).

`news:analyze` es la **única** forma de recuperar un análisis fallido: `news:pipeline` solo toma
los `pending`, y volver a scrapear tampoco reencola —un artículo que ya existe y no está
`pending` se salta a propósito, para no repetir llamadas que ya se pagaron—. Con `--retry-failed`
vuelven a la cola; `--limit` sirve para no agotar la cuota gratuita de una sola vez.

### Arañas (spiders)

Cada fuente tiene un spider que implementa `App\Contracts\SourceScraper`. Ninguno hace requests
por su cuenta: todos pasan por `SafeHttpFetcher`, la única puerta de salida a internet del
scraping, que centraliza allowlist de hosts, protección SSRF, redirecciones revalidadas salto a
salto, `robots.txt`, retardo entre requests, timeout, reintentos, tamaño máximo y codificación.

**Solo se activan las fuentes que tienen araña.** Una fuente activa sin `spider_class` no
recolecta nada y solo acumula fallos, así que las demás quedan inactivas con el motivo escrito
en `last_failure_reason`.

| Fuente | Araña | Estado |
|---|---|---|
| Diario Financiero · Mercados | `DiarioFinancieroSpider` (HTML) | activa |
| Pulso · La Tercera | `PulsoSpider` (HTML) | activa |
| BBC News Mundo · Economía | `BbcMundoEconomiaSpider` (RSS) | activa |
| Bloomberg Línea, El Mercurio Inversiones | — | inactivas, falta araña |
| Reuters | — | inactiva, responde 403 |

Las tres activas declaran en su `robots.txt` que permiten el acceso a las rutas que se leen.

> **Ninguna fuente chilena publica RSS.** Se probaron los feeds de Diario Financiero, Pulso,
> Emol, BioBioChile y El Mostrador: todos responden 404, redirigen a la portada o devuelven
> HTML. Por eso las dos chilenas se leen del listado HTML, y BBC Mundo —la única con RSS
> servible— quedó como respaldo. Ojo con esta última: su feed de `/economia` sirve contenido
> general de BBC Mundo, no solo económico.

**Se filtra lo que no es periodismo.** Los listados mezclan notas con publirreportajes,
contenido *branded* y videos. Cada araña declara en `articlePathPattern()` qué rutas son notas
de verdad: publicar publicidad pagada dentro del briefing, resumida por una IA y presentada
igual que una noticia, sería engañar al lector.

Dos capas de allowlist, ambas en `config/newsscraper.php`: `scraping.allowed_hosts` (a qué
dominios se sale) y `scraping.spiders` (qué clases puede instanciar el resolver). `spider_class`
es un dato de la base y `--spider=` viene de la consola: ninguno de los dos puede terminar
ejecutando una clase arbitraria.

**Qué se guarda de cada nota.** Los spiders RSS no abren el artículo: se quedan con el titular,
el enlace, la fecha y la bajada que el propio medio publica para ser sindicado, recortados a
`NEWS_MAX_CONTENT_CHARS` y `NEWS_MAX_EXCERPT_CHARS`. Nunca se almacena ni se republica el cuerpo
completo con copyright; lo que se muestra es el resumen generado más el enlace al original.

Para escribir una araña nueva: extender `App\Spiders\RssSpider` (o implementar el contrato para
HTML), agregar la clase a `scraping.spiders`, el host a `scraping.allowed_hosts` y asignar
`spider_class` en `SourceSeeder`.

## Rutas

Los controladores leen de la base de datos con eager loading; ninguna vista hace llamadas
externas. Los datos los escribe el pipeline (`php artisan news:pipeline`); con la base recién
migrada las rutas muestran su estado vacío, no un error.

| Ruta | Nombre | Contenido |
|---|---|---|
| `/` | `home` | Último briefing publicado |
| `/briefings` | `briefings.index` | Histórico de ediciones |
| `/briefings/{briefing}` | `briefings.show` | Una edición (AM/PM) |
| `/eventos/{event}` | `events.show` | Acontecimiento con todas sus fuentes |
| `/categorias/{category}` | `categories.show` | Acontecimientos por categoría |
| `/mercados` | `markets.index` | Panel de datos de mercado |

`/?vacio=1` fuerza el estado "todavía no hay briefing", útil para revisar ese caso en diseño.

## Documentación

| Archivo | Para qué |
|---|---|
| `README.md` | Instalar y usar (este archivo) |
| `ARQUITECTURA.md` | Qué es cada carpeta y cada archivo, el esquema de la base, cómo funciona cada feature |
| `PLAN.md` | Plan de implementación, estado y pendientes |
| `AUDITORIA-UI.md` | Sistema de diseño y decisiones de interfaz |
| `CLAUDE.md` | Contexto, decisiones técnicas y reglas de trabajo del equipo |

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
