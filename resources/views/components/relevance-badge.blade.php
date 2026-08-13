@props([
    'relevance',
])

{{--
    Relevancia con doble codificación: cuadrados macizos (cuántos) + etiqueta de
    texto. Nunca solo color — el lector daltónico y la miniatura en escala de
    grises leen lo mismo (AUDITORIA-UI.md H1).
--}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 text-xs font-semibold tracking-wide uppercase']) }}>
    <span class="flex items-center gap-0.5" aria-hidden="true">
        @for ($step = 1; $step <= 4; $step++)
            <span @class([
                'size-2',
                'bg-accent' => $step <= $relevance->marks() && $relevance->isProminent(),
                'bg-muted' => $step <= $relevance->marks() && ! $relevance->isProminent(),
                'border border-edge' => $step > $relevance->marks(),
            ])></span>
        @endfor
    </span>
    <span @class(['text-accent-strong' => $relevance->isProminent(), 'text-muted' => ! $relevance->isProminent()])>
        {{ $relevance->label() }}
    </span>
</span>
