@props([
    'markets',
])

{{-- Franja de mercado: contexto numérico antes de la lectura del briefing. --}}
<section {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }} aria-labelledby="franja-mercado">
    <div class="flex items-baseline justify-between gap-4">
        <h2 id="franja-mercado" class="text-xs font-semibold tracking-[0.08em] text-muted uppercase">
            Mercado en este momento
        </h2>
        <a href="{{ route('markets.index') }}" class="text-xs font-semibold text-accent-strong hover:underline hover:underline-offset-4">
            Ver todos los instrumentos
        </a>
    </div>

    <ul class="grid grid-cols-2 gap-px bg-rule sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($markets as $market)
            <li class="flex flex-col gap-1 bg-paper px-3 py-3">
                <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ $market->name }}</p>
                <p class="tabular font-heading text-lg font-extrabold">
                    {{ number_format($market->price, $market->price < 100 ? 2 : 0, ',', '.') }}
                </p>
                <x-market-change :percent="$market->change_percent" class="text-xs" />
            </li>
        @endforeach
    </ul>
</section>
