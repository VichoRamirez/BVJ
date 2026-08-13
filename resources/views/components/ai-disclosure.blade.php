@props([
    'compact' => false,
])

{{--
    Aviso persistente de contenido generado por IA. Va junto al resumen, no
    escondido en el pie: el lector tiene que saber qué está leyendo en el mismo
    golpe de vista en que lo lee (CLAUDE.md §4).
--}}
@if ($compact)
    <p {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase']) }}>
        <x-icon name="sparkles" class="size-3.5" />
        Resumen generado por IA
    </p>
@else
    <aside {{ $attributes->merge(['class' => 'flex items-start gap-3 border border-edge bg-surface px-4 py-3 text-sm']) }}>
        <x-icon name="sparkles" class="mt-0.5 size-5 text-accent-strong" />
        <p>
            <strong class="font-semibold">Los resúmenes de esta página los genera un modelo de lenguaje</strong>
            a partir de las publicaciones originales. Pueden contener errores u omisiones:
            cada acontecimiento enlaza a sus fuentes para que puedas verificarlo.
        </p>
    </aside>
@endif
