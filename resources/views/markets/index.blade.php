<x-layouts.app title="Mercados">
    <div class="mx-auto w-full max-w-6xl px-5 sm:px-8">

        <div class="flex flex-col gap-4 pt-10 pb-8">
            <p class="text-sm font-semibold tracking-[0.08em] text-accent-strong uppercase">Datos de mercado</p>
            <h1 class="font-heading text-4xl font-extrabold tracking-tight sm:text-5xl">Mercados</h1>
            <p class="max-w-[62ch] text-muted">
                Contexto numérico para leer el briefing. Los datos provienen de Yahoo Finance y se
                capturan junto con cada corrida del pipeline.
            </p>
            @if ($markets->isNotEmpty())
                <p class="tabular text-xs text-muted">
                    Última captura: {{ $markets->first()->captured_at->translatedFormat('j \d\e F, H:i') }} h (Chile)
                </p>
            @endif
        </div>

        <x-rule />

        @if ($markets->isEmpty())
            <div class="py-8">
                <x-empty-state title="Todavía no hay datos de mercado" icon="chart">
                    Las cotizaciones aparecerán aquí cuando termine la próxima captura del mercado.
                </x-empty-state>
            </div>
        @else
            <div class="overflow-x-auto py-8">
                <table class="w-full min-w-[40rem] text-left">
                <caption class="sr-only">Instrumentos seguidos, con su precio, variación diaria y tendencia de las últimas diez sesiones</caption>
                <thead>
                    <tr class="border-b-2 border-rule">
                        <th scope="col" class="py-3 pr-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase">Instrumento</th>
                        <th scope="col" class="py-3 pr-4 text-right text-xs font-semibold tracking-[0.08em] text-muted uppercase">Último</th>
                        <th scope="col" class="py-3 pr-4 text-right text-xs font-semibold tracking-[0.08em] text-muted uppercase">Variación</th>
                        <th scope="col" class="py-3 pr-4 text-xs font-semibold tracking-[0.08em] text-muted uppercase">10 sesiones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($markets as $market)
                        <tr class="border-b border-edge hover:bg-surface">
                            <th scope="row" class="py-4 pr-4 align-middle">
                                <span class="block font-heading font-extrabold">{{ $market->name }}</span>
                                <span class="block text-xs font-normal text-muted">{{ $market->detail }}</span>
                            </th>
                            <td class="tabular py-4 pr-4 text-right align-middle font-semibold">
                                {{ number_format($market->price, $market->price < 100 ? 2 : 0, ',', '.') }}
                                <span class="text-xs font-normal text-muted">{{ $market->unit }}</span>
                            </td>
                            <td class="py-4 pr-4 text-right align-middle">
                                <x-market-change :percent="$market->change_percent" class="justify-end" />
                            </td>
                            <td class="py-4 pr-4 align-middle">
                                @if (count($market->history) > 1)
                                    <x-sparkline
                                        :points="$market->history"
                                        :positive="$market->change_percent >= 0"
                                        :label="'Tendencia de '.$market->name.' en las últimas diez sesiones: '.($market->change_percent >= 0 ? 'al alza' : 'a la baja')"
                                    />
                                @else
                                    {{-- Un solo cierre no dibuja una línea. Pasa cuando el proveedor
                                         deja de actualizar un instrumento: mejor decirlo que mostrar
                                         una celda vacía que parece un error de la página. --}}
                                    <span class="text-xs text-muted">Sin serie disponible</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                </table>
            </div>
        @endif

        <p class="max-w-[62ch] pb-10 text-xs text-muted">
            Los gráficos son SVG en línea, dibujados con los tokens de serie del sistema de diseño.
            No dependen de JavaScript ni de una librería externa.
        </p>

    </div>
</x-layouts.app>
