<x-layouts.app title="Briefings anteriores">
    <div class="mx-auto w-full max-w-6xl px-5 sm:px-8">

        <div class="flex flex-col gap-4 pt-10 pb-8">
            <p class="text-sm font-semibold tracking-[0.08em] text-accent-strong uppercase">Archivo</p>
            <h1 class="font-heading text-4xl font-extrabold tracking-tight sm:text-5xl">Briefings anteriores</h1>
            <p class="max-w-[62ch] text-muted">
                Dos ediciones por día: una a las 07:00 y otra a las 18:00, hora de Chile.
                Cada edición reúne los acontecimientos más relevantes del período.
            </p>
        </div>

        <x-rule />

        @if ($days->isEmpty())
            <div class="py-16">
                <x-empty-state title="Todavía no hay ediciones publicadas" icon="calendar">
                    En cuanto el pipeline complete su primera corrida, las ediciones aparecerán aquí.
                </x-empty-state>
            </div>
        @else
            <table class="w-full text-left">
                <caption class="sr-only">Ediciones publicadas, de la más reciente a la más antigua</caption>
                <thead>
                    <tr class="border-b-2 border-rule">
                        <th scope="col" class="py-3 pr-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase">Fecha</th>
                        <th scope="col" class="py-3 pr-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase">Edición</th>
                        <th scope="col" class="hidden py-3 pr-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase sm:table-cell">Acontecimientos</th>
                        <th scope="col" class="hidden py-3 pr-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase md:table-cell">Titular principal</th>
                        <th scope="col" class="py-3"><span class="sr-only">Acción</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($days as $date => $editions)
                        @foreach ($editions as $briefing)
                            <tr class="border-b border-edge hover:bg-surface">
                                <th scope="row" class="tabular py-4 pr-4 align-top text-sm font-semibold">
                                    @if ($loop->first)
                                        {{ ucfirst($briefing->published_on->translatedFormat('l j \d\e F')) }}
                                    @else
                                        <span class="sr-only">{{ ucfirst($briefing->published_on->translatedFormat('l j \d\e F')) }}</span>
                                    @endif
                                </th>
                                <td class="py-4 pr-4 align-top">
                                    <span class="inline-flex items-center gap-2 text-sm font-semibold">
                                        <x-icon :name="$briefing->edition === \App\Enums\BriefingEdition::Morning ? 'sunrise' : 'sunset'" class="size-4 text-muted" />
                                        {{ $briefing->edition->shortLabel() }}
                                        <span class="tabular text-xs font-normal text-muted">{{ $briefing->published_at->format('H:i') }} h</span>
                                    </span>
                                </td>
                                <td class="tabular hidden py-4 pr-4 align-top text-sm sm:table-cell">{{ $briefing->events->count() }}</td>
                                <td class="hidden max-w-[36ch] py-4 pr-4 align-top text-sm text-muted md:table-cell">
                                    {{ $briefing->events->first()?->title }}
                                </td>
                                <td class="py-4 text-right align-top">
                                    <a
                                        href="{{ route('briefings.show', $briefing->id) }}"
                                        class="touch-target inline-flex items-center gap-1.5 text-sm font-semibold text-accent-strong hover:underline hover:underline-offset-4"
                                    >
                                        Abrir
                                        <span class="sr-only">la edición de la {{ $briefing->edition->shortLabel() }} del {{ $briefing->published_on->translatedFormat('j \d\e F') }}</span>
                                        <x-icon name="chevron-right" class="size-4" />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endif

    </div>
</x-layouts.app>
