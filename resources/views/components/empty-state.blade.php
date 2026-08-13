@props([
    'title',
    'icon' => 'inbox',
])

{{-- Estado vacío con explicación y salida: nunca una página en blanco. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-start gap-4 border border-edge bg-surface px-6 py-10']) }}>
    <x-icon :name="$icon" class="size-8 text-muted" />

    <h2 class="font-heading text-2xl font-extrabold">{{ $title }}</h2>

    @if (! empty(trim($slot)))
        <div class="max-w-prose text-muted">{{ $slot }}</div>
    @endif

    @isset($action)
        <div class="mt-2 flex flex-wrap gap-3">{{ $action }}</div>
    @endisset
</div>
