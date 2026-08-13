<x-layouts.app :title="$briefing ? $briefing->edition->label() : 'Sin briefing todavía'">
    <div class="mx-auto w-full max-w-6xl px-5 sm:px-8">

        @if ($briefing === null)
            {{-- Estado vacío: el pipeline aún no ha publicado nada hoy. --}}
            <div class="py-16">
                <x-empty-state title="Todavía no hay briefing publicado" icon="inbox">
                    La próxima edición se publica a las 07:00 h de Chile. Mientras tanto puedes revisar
                    las ediciones anteriores o el panel de mercado.

                    <x-slot:action>
                        <a href="{{ route('briefings.index') }}" class="touch-target inline-flex items-center bg-accent-fill px-4 py-2.5 font-semibold text-paper hover:bg-accent-strong">
                            Ver briefings anteriores
                        </a>
                        <a href="{{ route('markets.index') }}" class="touch-target inline-flex items-center border border-edge px-4 py-2.5 font-semibold hover:bg-surface">
                            Ir a mercados
                        </a>
                    </x-slot:action>
                </x-empty-state>
            </div>
        @else
            <div class="flex flex-col gap-8 pt-10 pb-6">
                <x-briefing-header :briefing="$briefing" :siblings="$siblings" />
                <x-market-strip :markets="$markets" />
                <x-source-status :sources="$sources" />
                <x-ai-disclosure />
            </div>

            <x-rule />

            <x-event-list :events="$briefing->events" />

            <x-rule />

            <div class="flex flex-col gap-8 py-10">
                <x-category-nav :counts="$categoryCounts" />

                <a
                    href="{{ route('briefings.index') }}"
                    class="touch-target inline-flex w-fit items-center gap-2 border border-edge px-4 py-2.5 font-semibold hover:bg-surface"
                >
                    Ver todas las ediciones
                    <x-icon name="chevron-right" class="size-4" />
                </a>
            </div>
        @endif

    </div>
</x-layouts.app>
