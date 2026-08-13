@props([
    'source',
    'href' => null,
])

{{-- El enlace a la publicación original es un requisito del proyecto, no un
     adorno: cada fuente citada debe poder abrirse. --}}
@if ($href)
    <a
        href="{{ $href }}"
        target="_blank"
        rel="noopener noreferrer external"
        {{ $attributes->merge(['class' => 'touch-target inline-flex items-center gap-1.5 bg-surface px-2.5 py-1 text-xs font-semibold hover:text-accent-strong']) }}
    >
        {{ $source->name }}
        <x-icon name="external-link" class="size-3.5" />
        <span class="sr-only">(se abre en el sitio de {{ $source->name }})</span>
    </a>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center bg-surface px-2.5 py-1 text-xs font-semibold']) }}>
        {{ $source->name }}
    </span>
@endif
