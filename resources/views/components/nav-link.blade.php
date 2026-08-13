@props([
    'href',
    'active' => false,
    'block' => false,
])

{{-- La ubicación actual se marca con color *y* subrayado: nunca solo con color. --}}
<li>
    <a
        href="{{ $href }}"
        @if ($active) aria-current="page" @endif
        {{ $attributes->merge(['class' => 'touch-target inline-flex items-center text-sm font-semibold hover:text-accent-strong '
            .($block ? 'w-full px-3 py-3 ' : '')
            .($active ? 'text-accent-strong underline decoration-2 underline-offset-8' : '')]) }}
    >
        {{ $slot }}
    </a>
</li>
