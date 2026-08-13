@props([
    'sources',
])

@php
    $failing = $sources->where('is_active', false);
@endphp

{{--
    Fallo parcial del pipeline: una fuente caída se informa, no se esconde ni
    voltea la página. El briefing se publica igual con las fuentes que sí
    respondieron (CLAUDE.md §4).
--}}
@if ($failing->isNotEmpty())
    <aside {{ $attributes->merge(['class' => 'flex items-start gap-3 border-l-2 border-accent bg-surface px-4 py-3 text-sm']) }} role="status">
        <x-icon name="alert-triangle" class="mt-0.5 size-5 text-accent-strong" />
        <p>
            <strong class="font-semibold">{{ $failing->pluck('name')->join(', ', ' y ') }}</strong>
            {{ $failing->count() === 1 ? 'no respondió' : 'no respondieron' }} en la última recolección.
            Este briefing se armó con las demás fuentes.
        </p>
    </aside>
@endif
