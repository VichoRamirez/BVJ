@props([
    'percent',
])

@php
    $direction = match (true) {
        $percent > 0.001 => 'up',
        $percent < -0.001 => 'down',
        default => 'flat',
    };
@endphp

{{--
    Variación de mercado con triple codificación: flecha, signo y color. El color
    es el último de los tres, nunca el único (AUDITORIA-UI.md H1).
--}}
<span
    @class([
        'tabular inline-flex items-center gap-1 text-sm font-semibold',
        'text-positive' => $direction === 'up',
        'text-negative' => $direction === 'down',
        'text-muted' => $direction === 'flat',
    ])
>
    <x-icon
        :name="match ($direction) { 'up' => 'arrow-up', 'down' => 'arrow-down', default => 'minus' }"
        class="size-4"
    />
    {{ $percent > 0 ? '+' : '' }}{{ number_format($percent, 2, ',', '.') }}%
    <span class="sr-only">{{ match ($direction) { 'up' => 'al alza', 'down' => 'a la baja', default => 'sin cambios' } }}</span>
</span>
