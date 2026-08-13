@props([
    'points',
    'positive' => true,
    'label' => null,
])

@php
    $values = array_values($points);
    $min = min($values);
    $max = max($values);
    $span = ($max - $min) ?: 1;
    $width = 120;
    $height = 32;
    $step = count($values) > 1 ? $width / (count($values) - 1) : $width;

    $coordinates = collect($values)
        ->map(fn (float|int $value, int $index): string => round($index * $step, 2).','.round($height - (($value - $min) / $span) * ($height - 4) - 2, 2))
        ->join(' ');
@endphp

{{--
    Placeholder de gráfico: SVG en línea sin dependencias. Cuando entre
    laravel-charts, este componente es el punto de reemplazo — el color de la
    serie ya sale de los tokens semánticos, no de la paleta por defecto de la
    librería (AUDITORIA-UI.md H10).
--}}
<svg
    viewBox="0 0 {{ $width }} {{ $height }}"
    preserveAspectRatio="none"
    fill="none"
    role="img"
    aria-label="{{ $label ?? 'Tendencia de las últimas diez sesiones' }}"
    {{ $attributes->merge(['class' => 'h-8 w-30']) }}
>
    <polyline
        points="{{ $coordinates }}"
        stroke="currentColor"
        stroke-width="2"
        stroke-linejoin="round"
        stroke-linecap="round"
        @class(['text-positive' => $positive, 'text-negative' => ! $positive])
        vector-effect="non-scaling-stroke"
    />
</svg>
