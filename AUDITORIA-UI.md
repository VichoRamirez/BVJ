# Auditoría UI/UX — sistema de diseño "Modernist"

**Auditado:** proyecto de Claude Design `Modernist` (`c8f50354-a1d6-4255-b1e8-4b09dce491d1`), estado del 2026-08-12.
**Contra:** heurísticas de `ui-ux-pro-max` (accesibilidad, interacción táctil, tipografía y color, formularios, navegación, datos) + WCAG 2.2 AA + el alcance de NewsScraper definido en `CLAUDE.md`.
**Método:** lectura de `theme.json`, `styles.css`, `readme.md`, `templates/landing/index.html` y los componentes (`cards`, `table`, `dialog`). Contrastes calculados sobre los tokens reales, no estimados a ojo.
**Autor:** revisión asistida (Claude). Ninguna de las correcciones está aplicada todavía.

---

## 0. Advertencia de alcance

Lo que hay en el proyecto de Design **es un sistema de diseño, no un mockup de NewsScraper**. `templates/landing/index.html` publicita un producto inventado ("Takt", horarios ferroviarios suizos) y `components/table.html` muestra un CMS genérico (Page / Status / Owner). No existe todavía ninguna pantalla del dominio: ni briefing AM/PM, ni Event, ni ficha de Article, ni panel de mercado.

Eso importa para leer esta auditoría: **lo que se audita es la base**, y la base es sólida y coherente. Pero el trabajo grande que falta no es corregir el sistema, es **derivar de él las pantallas del producto** (§5). Los hallazgos de las secciones 2–4 hay que arreglarlos *antes* de dibujar esas pantallas, porque si no se propagan a cada vista Blade.

**Veredicto general:** el sistema es consistente, está bien documentado y su `readme.md` demuestra criterio real (ya identifica que el acento no sirve para texto pequeño). Los problemas serios son tres: **contraste de los textos secundarios**, **el rojo como único color semántico en un producto financiero**, y **áreas táctiles bajo el mínimo**. Nada de eso es estructural: se arregla en la capa de tokens.

---

## 1. Resumen de hallazgos

| # | Hallazgo | Prioridad | Dónde |
|---|---|---|---|
| H1 | Rojo mono como única señal semántica en un producto financiero | **P0** | `theme.json`, `styles.css` |
| H2 | `.card-meta`, `.text-muted`, `figcaption` bajo 4.5:1 | **P0** | `styles.css` |
| H3 | `--color-divider` a 2.41:1 — bordes de input y filas de tabla bajo el mínimo de 3:1 | **P0** | `styles.css` |
| H4 | `.card-kicker` en acento a 10px → 3.47:1 (contradice el propio `readme.md`) | **P0** | `styles.css` |
| H5 | Áreas táctiles de 31–36px (mínimo 44px) | **P0** | `.btn`, `.input`, `.seg-opt`, `.btn-icon` |
| H6 | Tamaños de lectura por debajo de lo cómodo (15/13/11px) en un producto cuyo valor *es* leer | **P1** | `styles.css` |
| H7 | Sin modo oscuro | **P1** | tokens |
| H8 | Cero transiciones: todos los cambios de estado son instantáneos | **P1** | `styles.css` |
| H9 | El nav oculta *todos* los enlaces bajo 480px | **P1** | `templates/landing` |
| H10 | Sin tokens de gráficos — `laravel-charts` va a entrar con su paleta por defecto | **P1** | falta |
| H11 | Faltan estados de sistema: vacío, carga, error, "sin briefing todavía" | **P1** | falta |
| H12 | Sin cifras tabulares en `.table` | **P2** | `styles.css` |
| H13 | Fuentes por `@import` de Google Fonts (bloqueante, sin `preconnect`) | **P2** | `styles.css` |
| H14 | `--color-accent-2-*` es un relleno idéntico al acento → `.tag-accent-2` es código muerto | **P2** | tokens |
| H15 | `text-box: trim-both` sin fallback (soporte de navegador muy reciente) | **P2** | `templates/landing` |
| H16 | Sin `<main>` ni skip-link en la plantilla | **P2** | `templates/landing` |

---

## 2. P0 — Bloqueantes

### H1. El rojo mono es un problema semántico, no estético

El sistema es deliberadamente monocromo: un solo acento `#ec3013`, y `--color-accent-2` es un relleno generado por máquina que el propio `readme.md` admite que "se lee igual que el acento". Estéticamente funciona. En un producto financiero **el rojo ya significa algo**: caída, pérdida, alerta. Un briefing donde el kicker de categoría, el botón primario, el número destacado y el banner de cierre son todos rojos le dice al lector "malas noticias" en cada pantalla, sin querer decirlo.

Además, NewsScraper necesita codificar visualmente al menos cuatro ejes que hoy no tienen color asignado:

- `RelevanceLevel` (alta / media / baja)
- `NewsCategory` (varias categorías simultáneas en un briefing)
- Variación de mercado (sube / baja / plano) para los datos de Yahoo Finance
- Estado del análisis (`ok` / `failed`), y el distintivo de "resumen generado por IA"

**Sugerencia:** conservar Modernist tal cual como lenguaje de marca, y **añadir una capa de tokens semánticos** por encima — no un segundo acento decorativo, sino roles con significado. Mantener el rojo reservado para la acción primaria y el cierre-póster; que el dato financiero use su propia pareja.

```css
:root {
  /* Roles semánticos — encima de las rampas existentes, no en lugar de ellas */
  --color-positive:     #0f6b3d;  /* alza de mercado */
  --color-positive-bg:  #e6f2eb;
  --color-negative:     var(--color-accent-700);  /* baja: reusa la rampa, 6.41:1 */
  --color-negative-bg:  var(--color-accent-100);
  --color-neutral-flat: var(--color-neutral-700);

  /* Relevancia — se distingue por peso e ink, no por matiz */
  --relevance-high:   var(--color-accent);        /* + marca cuadrada roja */
  --relevance-medium: var(--color-neutral-700);
  --relevance-low:    var(--color-neutral-500);
}
```

**Regla dura (`color-not-only`):** ni la relevancia ni la variación de mercado pueden depender solo del color. Alta relevancia lleva **el cuadrado rojo de 10px que ya existe** en `.f-num::before` + etiqueta de texto; el mercado lleva flecha ▲/▼ (SVG Lucide, no emoji) + el signo en el número. Esto no es solo daltonismo: las miniaturas del sistema van en escala de grises, y el lector de Federico Valdés escanea en 2 minutos sin leer leyendas.

### H2. Textos secundarios bajo el mínimo de contraste

Medido sobre los tokens reales:

| Regla | Color resultante | Contraste | Requiere | Estado |
|---|---|---|---|---|
| `.card-meta` (11px) | ink 50% sobre `--color-surface` | **3.08:1** | 4.5:1 | ✗ |
| `.text-muted` (usado a 14px en `.table`) | ink 55% sobre `--color-bg` | **3.66:1** | 4.5:1 | ✗ |
| `figcaption` (11px) | ink 55% | **3.66:1** | 4.5:1 | ✗ |
| `.table th` (11px) | ink 60% | 4.23:1 | 4.5:1 | ✗ (al borde) |
| `.field > label` (12px) | ink 70% | 5.79:1 | 4.5:1 | ✓ |
| `.card-body` (13px, opacity .8) | — | 7.65:1 | 4.5:1 | ✓ |

`.card-meta` es exactamente donde van **fuente, fecha y hora** del artículo — el metadato que la propuesta exige mostrar siempre. No puede ser el texto menos legible de la tarjeta.

Lo llamativo es que **la plantilla landing ya descubrió este bug y lo parcheó localmente**: sus comentarios dicen literalmente que 55% mide 3.7:1 y por eso usa 70%. Esa corrección nunca volvió a `styles.css`. Hay que subirla al token para que no se repita en cada vista Blade.

```css
/* Un solo token para "texto secundario", en vez de 50/55/60% dispersos */
:root { --color-text-muted: color-mix(in srgb, var(--color-text) 70%, transparent); } /* 5.79:1 */

.text-muted, figcaption, .card-meta, .table th { color: var(--color-text-muted); }
```

### H3. El divisor no llega al mínimo de 3:1

`--color-divider` es ink al 40% → **2.41:1** sobre el fondo. Como regla de sección decorativa a 2px de grosor da igual. Pero el mismo token dibuja **el borde de `.input`, el borde de `.seg` y las filas de `.table`**, y ahí es un límite de componente: WCAG 1.4.11 pide 3:1. Un formulario cuyo campo no se distingue del fondo falla el criterio.

```css
:root {
  --color-divider: color-mix(in srgb, var(--color-text) 40%, transparent); /* reglas: OK */
  --color-border:  color-mix(in srgb, var(--color-text) 55%, transparent); /* controles: 3.66:1 */
}
.input, .seg, .btn-secondary { border-color: var(--color-border); }
```

### H4. El kicker se contradice con el readme

`.card-kicker` es 10px, mayúsculas, en `--color-accent` → **3.47:1**. El propio `readme.md` dice, correctamente: *"para texto tamaño párrafo en el acento usa un paso profundo de la rampa (`--color-accent-700`)"*. El componente hace justo lo contrario, y a un tamaño aún menor. Además 10px con `letter-spacing` está por debajo de cualquier umbral razonable de lectura.

```css
.card-kicker { font-size: 12px; color: var(--color-accent-700); } /* 6.41:1 */
```

Mismo problema en `.tag-outline` (11px en acento puro, 3.76:1) y en el `.btn-ghost` blanco sobre el campo rojo del cierre (3.76:1). Este último está documentado como una decisión consciente ("cromo de interfaz, 3:1"), y para un botón es defendible — pero es **el único CTA de esa sección**. Sugerencia: dejarlo sólido (`background: var(--color-bg); color: var(--color-accent-700)`) en vez de ghost.

### H5. Áreas táctiles por debajo de 44px

| Elemento | Altura calculada | Mínimo |
|---|---|---|
| `.btn` (8px + 8px + 17px línea) | ~33px | 44px |
| `.input` (`min-height`) | 36px | 44px |
| `.btn-icon` | 36×36px | 44×44px |
| `.seg-opt` (7px + 7px + ~16px) | ~31px | 44px |
| `.radio .dot` | 16px (área de toque = la fila) | 44px |

Ambos perfiles de usuario leen en el teléfono. Con densidad 1.00× esto es un ajuste de tokens, no un rediseño:

```css
@media (pointer: coarse) {
  .btn { padding-block: var(--space-3); }         /* → 44px */
  .input, .seg-opt { min-height: 44px; }
  .btn-icon { width: 44px; height: 44px; }
  .radio { min-height: 44px; }
}
```

Y en cualquier puntero: `.input` a 44px evita además el auto-zoom de iOS si se combina con `font-size: 16px` (ver H6).

---

## 3. P1 — Importantes

### H6. La escala tipográfica es pequeña para un producto de lectura

`body` a 15px, `.card-body` a 13px, `.card-meta`/`.tag`/`figcaption` a 11px. La heurística pide 16px de base en móvil; por debajo de eso iOS hace zoom automático al enfocar un input. Para un producto cuyo trabajo entero es que alguien lea 8 resúmenes en 3 minutos, 13px es una decisión cara.

Además, `Archivo` es una grotesca: excelente para titulares, mayúsculas y cifras (que es lo que Modernist explota), menos cómoda para párrafos largos. `ui-ux-pro-max` recomienda para producto editorial/noticias una serif de titular + neutra de cuerpo (`Newsreader` + `Roboto`).

**Sugerencia mínima (sin tocar la identidad):** subir la escala y el interlineado, conservando Archivo.

```css
body { font-size: 16px; line-height: 1.6; }
.card-body { font-size: 15px; }
.card-meta, figcaption, .tag { font-size: 12px; }
```

**Sugerencia ambiciosa (evaluar con el equipo):** Archivo se queda en titulares, UI, cifras y etiquetas; una segunda familia legible toma el cuerpo del resumen. Es un cambio de identidad — decisión del equipo, no de la auditoría. Si se hace, `--font-body` es el único punto a tocar.

En ambos casos: limitar la medida del resumen a `--measure: 58ch` (ya existe en la landing, no en `styles.css`). Hoy `.card-body` dentro de un grid de 3 columnas no tiene control de medida.

### H7. Sin modo oscuro

El sistema es solo claro. El briefing de la tarde se lee de noche, y `theme.json` marca `"band": "light"` sin contraparte. Como las rampas ya están generadas en OKLCH sobre una escala perceptual compartida, invertir los pasos es barato — pero **hay que verificar los contrastes por separado** (`color-dark-mode`): el rojo `#ec3013` sobre fondo oscuro cambia de valor y probablemente hay que subir a `--color-accent-400`.

```css
@media (prefers-color-scheme: dark) {
  :root {
    --color-bg: #171616; --color-surface: #201e1d; --color-text: #f3f2f2;
    --color-accent: var(--color-accent-400);
    --color-text-muted: color-mix(in srgb, var(--color-text) 72%, transparent);
  }
}
```

### H8. Cero transiciones

No hay una sola `transition` en `styles.css`. Todos los `:hover` / `:active` / `:checked` saltan en 0ms. La heurística pide 150–300ms para micro-interacciones — la diferencia entre "se siente barato" y "se siente terminado".

```css
.btn, .input, .seg-opt, .table tbody tr, .radio .dot { transition: background-color .18s ease-out, border-color .18s ease-out, color .18s ease-out; }
@media (prefers-reduced-motion: reduce) { * { transition-duration: .01ms !important; } }
```

Solo `background-color`/`color`/`border-color`/`opacity`/`transform` — nunca `width`/`height`.

### H9. El nav desaparece en móvil

`templates/landing/index.html` hace `@media (max-width: 480px) { .nav a { display: none } }`. Bajo 480px el usuario se queda con la marca y un botón: **no hay navegación**. Para NewsScraper eso significa perder el acceso a "Briefings anteriores" y "Mercado" justo en el ancho donde más gente lee. Necesita un menú real (`<details>`/`<summary>` sin JS mantiene la promesa de "no framework") o una barra inferior de ≤5 destinos.

### H10. Faltan tokens de visualización de datos

`laravel-charts` va a renderizar con la paleta por defecto de Chart.js, que chocará frontalmente con un sistema mono de rojo y tinta. Hay que definir antes de la primera integración:

- Paleta categórica de series (derivarla de las rampas neutral + acento; máximo 5 series)
- Token de línea de grilla (bajo contraste, ~`--color-neutral-300`)
- Contraste dato/fondo ≥3:1, etiquetas ≥4.5:1
- **Los gráficos quedan exentos de `.grayscale`** — esa regla es para fotografía de prensa; aplicarla a un gráfico destruye la codificación de la serie
- Cifras tabulares en ejes y etiquetas
- Estado vacío ("sin datos de mercado para este período") y estado de error con reintento, en vez de un marco de ejes vacío

### H11. Faltan los estados de sistema

El sistema tiene componentes de contenido pero ningún estado de proceso, y este producto los necesita a diario porque **el pipeline es asíncrono y puede fallar por fuente**:

- Vacío: "aún no hay briefing de la tarde" (con la hora prevista de publicación)
- Carga: esqueleto para tarjetas y gráficos (>300ms)
- Error parcial: "Bloomberg no respondió en esta corrida" — sin voltear la página
- Análisis `failed`: cómo se ve un Article que el LLM no pudo analizar
- Toast / alerta con `aria-live`

---

## 4. P2 — Mejoras

- **H12.** `.table` sin `font-feature-settings: "tnum"`. En columnas de precios y variaciones los dígitos bailan al actualizarse. La landing ya lo usa en sus kickers; súbelo al componente. Añadir también `aria-sort` si las columnas se ordenan.
- **H13.** `styles.css` abre con `@import url(fonts.googleapis...)`. Un `@import` en CSS es serial y bloqueante: la hoja se descarga, *luego* empieza la fuente. Con Laravel + Vite conviene autohospedar Archivo o, como mínimo, mover a `<link rel="preconnect">` + `<link rel="stylesheet">` en el layout Blade.
- **H14.** `--color-accent-2-*` y `.tag-accent-2` son un duplicado del acento. Hoy es código muerto que invita a usarlo creyendo que codifica algo distinto. Eliminarlo, o reasignarlo a los roles semánticos de H1.
- **H15.** La landing usa `text-box: trim-both` y `1cap` extensivamente. Son propiedades muy recientes; en navegadores sin soporte el ritmo de 28px se descuadra silenciosamente. Verificar en Firefox/Safari o añadir márgenes de reserva.
- **H16.** La plantilla no tiene `<main>` ni skip-link, y el `<footer>` va suelto dentro de un `div`. Añadir `<main id="contenido">` y un `<a class="skip" href="#contenido">` — barato y exigido por `skip-links` / `focus-on-route-change`.
- **Dialog:** la demo trae `role="dialog"`, `aria-modal` y `aria-labelledby` correctos, pero es un `<div>`: no hay foco atrapado, ni cierre con Esc, ni botón de cerrar visible. Al implementarlo en Blade, usar `<dialog>` nativo con `showModal()`.
- `.tag` usa `calc(var(--radius-md) * 0.75)` = siempre 0. Inofensivo, pero sobra.
- `:focus { outline: none }` global depende por completo de `:focus-visible`. Correcto en navegadores actuales; verificar en modo de alto contraste de Windows (`forced-colors`).

---

## 5. Lo que falta construir: componentes del dominio

Ninguna pantalla de NewsScraper existe todavía. Antes de escribir Blade, el sistema necesita estas piezas (nombres en inglés, según la convención del proyecto):

| Componente | Por qué | Notas de diseño |
|---|---|---|
| **BriefingHeader** | Distinguir edición AM/PM de un vistazo | Fecha + hora en `America/Santiago` explícita; `.seg` existente sirve para alternar mañana/tarde |
| **EventCard** | La unidad real del briefing (no el Article) | Extiende `.card`: título, resumen, categoría, marca de relevancia, fila de fuentes |
| **SourceAttribution** | Un Event agrupa varios medios | Chips de fuente + "y 3 medios más"; cada uno enlaza al original |
| **RelevanceIndicator** | Priorización visible al escanear | Cuadrado rojo + etiqueta de texto (nunca solo color, ver H1) |
| **EntityChip** | Empresas y personas mencionadas | Reusa `.tag`; distinguir empresa/persona con icono Lucide, no con matiz |
| **AiDisclosure** | Requisito de `CLAUDE.md`: dejar claro que el resumen es de IA | Etiqueta persistente en cada resumen, no un aviso global en el pie |
| **OriginalLink** | Requisito legal y editorial | Afordancia visible en cada Event, no un enlace escondido en el título |
| **MarketWidget** | Datos de Yahoo Finance | Cifras tabulares + flecha; par positivo/negativo de H1 |
| **EmptyState / ErrorState / Skeleton** | Ver H11 | |
| **ArchiveList** | Briefings anteriores | La `.table` actual sirve con los arreglos de H2/H12 |

Y cuatro maquetas que deberían existir en el proyecto de Design antes de maquetar en Blade: **Briefing (home)**, **Detalle de Event**, **Archivo**, **Mercado**.

---

## 6. Parche de tokens sugerido

Consolidado de H1–H8, para pegar en `styles.css` después del bloque `:root` actual. **No aplicado.**

```css
:root {
  /* — texto secundario: un solo token en vez de 50/55/60% dispersos (H2) — */
  --color-text-muted: color-mix(in srgb, var(--color-text) 70%, transparent);

  /* — bordes de control, separados de las reglas decorativas (H3) — */
  --color-border: color-mix(in srgb, var(--color-text) 55%, transparent);

  /* — roles semánticos del dominio financiero (H1) — */
  --color-positive: #0f6b3d;
  --color-positive-bg: #e6f2eb;
  --color-negative: var(--color-accent-700);
  --color-negative-bg: var(--color-accent-100);

  /* — movimiento (H8) — */
  --motion-fast: .18s;
  --motion-ease: cubic-bezier(.2, 0, 0, 1);
}

body { font-size: 16px; line-height: 1.6; }                              /* H6 */
.text-muted, figcaption, .card-meta, .table th { color: var(--color-text-muted); }
.card-kicker { font-size: 12px; color: var(--color-accent-700); }        /* H4 */
.input, .seg, .btn-secondary { border-color: var(--color-border); }      /* H3 */
.table { font-feature-settings: "tnum" 1; }                              /* H12 */

@media (pointer: coarse) {                                               /* H5 */
  .btn { padding-block: var(--space-3); }
  .input, .seg-opt, .radio { min-height: 44px; }
  .btn-icon { width: 44px; height: 44px; }
}

.btn, .input, .seg-opt, .table tbody tr, .radio .dot {                   /* H8 */
  transition: background-color var(--motion-fast) var(--motion-ease),
              border-color var(--motion-fast) var(--motion-ease),
              color var(--motion-fast) var(--motion-ease);
}
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition-duration: .01ms !important; } }
```

### Puente a Tailwind 4

Para no terminar con dos escalas en paralelo (los tokens de Modernist y los defaults de Tailwind), exponerlos en el bloque `@theme` de la hoja principal:

```css
@import "tailwindcss";
@import "../design/modernist/styles.css";

@theme inline {
  --color-ink: var(--color-text);
  --color-paper: var(--color-bg);
  --color-accent: var(--color-accent);
  --font-heading: var(--font-heading);
  --radius-md: var(--radius-md);
}
```

Así `bg-paper`, `text-ink` y `border-border` salen del sistema, y nadie escribe un hex a mano en un Blade.

---

## 7. Checklist antes de la entrega

- [ ] Todo texto ≥4.5:1; cromo de interfaz y bordes ≥3:1 (recalcular tras el parche)
- [ ] Relevancia y variación de mercado nunca dependen solo del color
- [ ] Áreas táctiles ≥44px en 375px de ancho
- [ ] Probado en 375 / 768 / 1024 / 1440px, sin scroll horizontal
- [ ] Navegación accesible bajo 480px (H9)
- [ ] Modo oscuro verificado por separado, no inferido del claro
- [ ] `prefers-reduced-motion` respetado
- [ ] Foco de teclado visible en todo control; orden de tabulación = orden visual
- [ ] Iconos Lucide en SVG, ningún emoji como icono
- [ ] Cada resumen muestra el distintivo de IA y el enlace al original
- [ ] Gráficos con leyenda, tooltip, alternativa en tabla y estado vacío
- [ ] Estados vacío / carga / error implementados para el pipeline asíncrono
