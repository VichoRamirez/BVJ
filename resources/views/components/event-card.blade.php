@props([
    'event',
    'position' => null,
])

@php
    $sources = $event->articles->pluck('source')->unique('slug');
@endphp

{{--
    La unidad del briefing es el Event (el acontecimiento), no el artículo: un
    hecho contado por tres medios se lee una vez y muestra sus tres fuentes.

    Estructura tomada de las "feature rows" de Modernist: número a la izquierda,
    contenido a la derecha, regla de 2px como costura entre filas. El cuadrado
    rojo cuelga solo en los acontecimientos de relevancia alta.
--}}
<article {{ $attributes->merge(['class' => 'grid gap-x-8 gap-y-4 py-8 md:grid-cols-[4rem_1fr]']) }}>
    <div class="flex items-center gap-3 md:flex-col md:items-start md:gap-2">
        @if ($position !== null)
            <p class="tabular font-heading text-sm font-extrabold">{{ str_pad((string) $position, 2, '0', STR_PAD_LEFT) }}</p>
        @endif
        @if ($event->relevance->isProminent())
            <span class="size-2.5 bg-accent" aria-hidden="true"></span>
        @endif
    </div>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <x-relevance-badge :relevance="$event->relevance" />
            <x-category-tag :category="$event->category" />
            <span class="tabular text-xs text-muted">
                {{ $event->first_seen_at->translatedFormat('j \d\e M, H:i') }} h
            </span>
        </div>

        <h2 class="font-heading text-2xl font-extrabold tracking-tight text-balance sm:text-[1.75rem]">
            <a href="{{ route('events.show', $event->slug) }}" class="hover:text-accent-strong">
                {{ $event->title }}
            </a>
        </h2>

        <p class="max-w-[62ch] text-pretty">{{ $event->summary }}</p>

        <div class="border-l-2 border-accent pl-4">
            <p class="text-xs font-semibold tracking-[0.08em] text-accent-strong uppercase">Por qué importa</p>
            <p class="mt-1 max-w-[62ch] text-sm text-muted">{{ $event->importance }}</p>
        </div>

        <x-entity-list :entities="$event->entities" />

        <div class="flex flex-wrap items-center justify-between gap-4 pt-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold tracking-wide text-muted uppercase">
                    {{ $sources->count() }} {{ \Illuminate\Support\Str::plural('fuente', $sources->count()) }}
                </span>
                @foreach ($event->articles as $article)
                    <x-source-pill :source="$article->source" :href="$article->url" />
                @endforeach
            </div>

            <a
                href="{{ route('events.show', $event->slug) }}"
                class="touch-target inline-flex items-center gap-1.5 text-sm font-semibold text-accent-strong hover:underline hover:underline-offset-4"
            >
                Ver el acontecimiento
                <x-icon name="chevron-right" class="size-4" />
                <span class="sr-only">: {{ $event->title }}</span>
            </a>
        </div>
    </div>
</article>
