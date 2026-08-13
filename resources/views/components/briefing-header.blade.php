@props([
    'briefing',
    'siblings' => null,
])

@php
    $sourceCount = $briefing->events
        ->flatMap(fn ($event) => $event->articles->pluck('source.slug'))
        ->unique()
        ->count();
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-6']) }}>
    <div class="flex flex-wrap items-start justify-between gap-x-8 gap-y-4">
        <div class="flex flex-col gap-3">
            <p class="flex items-center gap-2 text-sm font-semibold tracking-[0.08em] text-accent-strong uppercase">
                <x-icon :name="$briefing->edition === \App\Enums\BriefingEdition::Morning ? 'sunrise' : 'sunset'" class="size-4" />
                {{ $briefing->edition->label() }}
            </p>

            <h1 class="font-heading text-4xl font-extrabold tracking-tight text-balance sm:text-5xl">
                {{ ucfirst($briefing->published_on->translatedFormat('l j \d\e F')) }}
            </h1>
        </div>

        {{-- Alternar entre las dos ediciones del mismo día --}}
        @if ($siblings?->count() > 1)
            <nav class="flex border border-edge" aria-label="Edición del día">
                @foreach ($siblings as $sibling)
                    <a
                        href="{{ route('briefings.show', $sibling->id) }}"
                        @if ($sibling->id === $briefing->id) aria-current="page" @endif
                        @class([
                            'touch-target flex items-center px-4 py-2 text-sm font-semibold',
                            'bg-accent-fill text-paper' => $sibling->id === $briefing->id,
                            'hover:bg-surface' => $sibling->id !== $briefing->id,
                        ])
                    >{{ $sibling->edition->shortLabel() }}</a>
                @endforeach
            </nav>
        @endif
    </div>

    <p class="tabular flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
        <span class="inline-flex items-center gap-1.5">
            <x-icon name="clock" class="size-4" />
            Publicado a las {{ $briefing->published_at->format('H:i') }} h
            <span class="sr-only">hora de Chile continental</span>
            (Chile)
        </span>
        <span aria-hidden="true">·</span>
        <span>{{ $briefing->events->count() }} {{ \Illuminate\Support\Str::plural('acontecimiento', $briefing->events->count()) }}</span>
        <span aria-hidden="true">·</span>
        <span>{{ $sourceCount }} {{ \Illuminate\Support\Str::plural('fuente', $sourceCount) }}</span>
    </p>
</div>
