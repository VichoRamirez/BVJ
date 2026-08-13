@props([
    'category',
    'linked' => true,
])

@if ($linked)
    <a
        href="{{ route('categories.show', $category->value) }}"
        {{ $attributes->merge(['class' => 'inline-flex items-center border border-edge px-2 py-1 text-xs font-semibold tracking-wide uppercase hover:border-accent hover:text-accent-strong']) }}
    >{{ $category->label() }}</a>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center border border-edge px-2 py-1 text-xs font-semibold tracking-wide uppercase']) }}>{{ $category->label() }}</span>
@endif
