<x-layouts.app :title="$category->label()">
    <div class="mx-auto w-full max-w-6xl px-5 sm:px-8">

        <div class="flex flex-col gap-4 pt-10 pb-8">
            <p class="text-sm font-semibold tracking-[0.08em] text-accent-strong uppercase">Categoría</p>
            <h1 class="font-heading text-4xl font-extrabold tracking-tight sm:text-5xl">{{ $category->label() }}</h1>
            <p class="tabular text-muted">
                {{ $events->count() }} {{ \Illuminate\Support\Str::plural('acontecimiento', $events->count()) }}
                {{ $events->count() === 1 ? 'registrado' : 'registrados' }}, del más relevante al menos relevante.
            </p>
        </div>

        <div class="pb-8">
            <x-category-nav :counts="$categoryCounts" :current="$category" />
        </div>

        <x-rule />

        @if ($events->isEmpty())
            <div class="py-16">
                <x-empty-state :title="'Sin acontecimientos en '.$category->label()" icon="newspaper">
                    Todavía no se ha clasificado ninguna noticia en esta categoría.
                    Vuelve después del próximo briefing.

                    <x-slot:action>
                        <a href="{{ route('home') }}" class="touch-target inline-flex items-center bg-accent-fill px-4 py-2.5 font-semibold text-paper hover:bg-accent-strong">
                            Ir al último briefing
                        </a>
                    </x-slot:action>
                </x-empty-state>
            </div>
        @else
            <x-event-list :events="$events" :numbered="false" />
        @endif

    </div>
</x-layouts.app>
