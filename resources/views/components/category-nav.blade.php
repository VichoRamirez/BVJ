@props([
    'counts',
    'current' => null,
])

{{-- Navegación secundaria por categoría. Vive en la página, no en la barra
     principal: primaria y secundaria se mantienen separadas. --}}
<nav {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }} aria-label="Categorías">
    <h2 class="text-xs font-semibold tracking-[0.08em] text-muted uppercase">Explorar por categoría</h2>
    <ul class="flex flex-wrap gap-2">
        @foreach (\App\Enums\NewsCategory::ordered() as $category)
            @continue(($counts[$category->value] ?? 0) === 0)
            <li>
                <a
                    href="{{ route('categories.show', $category->value) }}"
                    @if ($current === $category) aria-current="page" @endif
                    @class([
                        'touch-target inline-flex items-center gap-2 border px-3 py-1.5 text-sm font-semibold',
                        'border-accent bg-accent-soft text-accent-strong' => $current === $category,
                        'border-edge hover:border-accent hover:text-accent-strong' => $current !== $category,
                    ])
                >
                    {{ $category->label() }}
                    <span class="tabular text-xs text-muted">{{ $counts[$category->value] ?? 0 }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
