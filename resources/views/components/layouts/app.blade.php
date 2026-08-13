<!DOCTYPE html>
<html lang="es" class="scroll-pt-24">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="Briefing financiero automatizado: los acontecimientos económicos más relevantes del día, resumidos y agrupados por fuente.">

    <title>{{ $title ?? 'Briefing financiero' }} · {{ config('app.name') }}</title>

    {{-- Archivo se sirve desde Bunny (ver vite.config.js). Sin esta directiva el
         manifiesto de fuentes nunca se emite y toda la app cae al fallback. --}}
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh flex flex-col bg-paper text-ink antialiased">
    <a href="#contenido" class="skip-link">Saltar al contenido</a>

    <header class="border-b-2 border-rule">
        <div class="mx-auto w-full max-w-6xl px-5 sm:px-8">
            <nav class="flex items-center justify-between gap-6 py-4" aria-label="Navegación principal">
                <a href="{{ route('home') }}" class="font-heading text-lg font-extrabold tracking-tight uppercase">
                    News<span class="text-accent">Scraper</span>
                </a>

                {{-- Escritorio: la barra completa --}}
                <ul class="hidden items-center gap-7 sm:flex">
                    <x-nav-link :href="route('home')" :active="request()->routeIs('home')">Hoy</x-nav-link>
                    <x-nav-link :href="route('briefings.index')" :active="request()->routeIs('briefings.*')">Briefings</x-nav-link>
                    <x-nav-link :href="route('markets.index')" :active="request()->routeIs('markets.*')">Mercados</x-nav-link>
                </ul>

                {{-- Teléfono: menú desplegable sin JavaScript. La navegación nunca
                     desaparece por completo (AUDITORIA-UI.md H9). --}}
                <details class="relative sm:hidden [&[open]_.chevron]:rotate-180">
                    <summary class="touch-target flex cursor-pointer list-none items-center gap-2 border border-edge px-3 py-2 text-sm font-semibold">
                        Menú
                        <x-icon name="chevron-down" class="chevron size-4 transition-transform" />
                    </summary>
                    <ul class="absolute right-0 z-30 mt-2 w-56 border-2 border-ink bg-surface p-1 shadow-lg">
                        <x-nav-link :href="route('home')" :active="request()->routeIs('home')" block>Hoy</x-nav-link>
                        <x-nav-link :href="route('briefings.index')" :active="request()->routeIs('briefings.*')" block>Briefings anteriores</x-nav-link>
                        <x-nav-link :href="route('markets.index')" :active="request()->routeIs('markets.*')" block>Mercados</x-nav-link>
                    </ul>
                </details>
            </nav>
        </div>
    </header>

    <main id="contenido" class="flex-1">
        {{ $slot }}
    </main>

    <footer class="mt-16 border-t-2 border-rule">
        <div class="mx-auto w-full max-w-6xl px-5 py-8 sm:px-8">
            <div class="flex flex-col gap-4 text-sm text-muted sm:flex-row sm:items-start sm:justify-between">
                <p class="max-w-prose">
                    {{ config('app.name') }} recopila noticias financieras de medios públicos, las resume con un
                    modelo de lenguaje y publica dos briefings diarios. Los resúmenes son generados por IA:
                    verifica siempre contra la publicación original.
                </p>
                <p class="shrink-0">
                    IIP323W · Proyecto integrador Unidad 3
                </p>
            </div>
        </div>
    </footer>
</body>
</html>
