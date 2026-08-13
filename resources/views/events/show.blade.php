<x-layouts.app :title="$event->title">
    <div class="mx-auto w-full max-w-6xl px-5 sm:px-8">

        <div class="flex flex-col gap-6 pt-10 pb-8">
            <a href="{{ route('home') }}" class="inline-flex w-fit items-center gap-1.5 text-sm font-semibold text-muted hover:text-accent-strong">
                <x-icon name="chevron-right" class="size-4 rotate-180" />
                Volver al briefing
            </a>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <x-relevance-badge :relevance="$event->relevance" />
                <x-category-tag :category="$event->category" />
                <span class="tabular text-xs text-muted">
                    Primera publicación: {{ $event->first_seen_at->translatedFormat('j \d\e F \d\e Y, H:i') }} h (Chile)
                </span>
            </div>

            <h1 class="max-w-[24ch] font-heading text-4xl font-extrabold tracking-tight text-balance sm:text-5xl">
                {{ $event->title }}
            </h1>
        </div>

        <x-rule />

        <div class="grid gap-10 py-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="flex flex-col gap-8">
                <section class="flex flex-col gap-3" aria-labelledby="resumen">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="resumen" class="text-xs font-semibold tracking-[0.08em] text-muted uppercase">Resumen</h2>
                        <x-ai-disclosure compact />
                    </div>
                    <p class="max-w-[62ch] text-lg text-pretty">{{ $event->summary }}</p>
                </section>

                <section class="flex flex-col gap-3 border-l-2 border-accent pl-5" aria-labelledby="importancia">
                    <h2 id="importancia" class="text-xs font-semibold tracking-[0.08em] text-accent-strong uppercase">
                        Por qué importa
                    </h2>
                    <p class="max-w-[62ch] text-pretty">{{ $event->importance }}</p>
                </section>

                <x-rule />

                <section class="flex flex-col gap-5" aria-labelledby="cobertura">
                    <h2 id="cobertura" class="text-xs font-semibold tracking-[0.08em] text-muted uppercase">
                        Cobertura: {{ $event->articles->count() }} {{ \Illuminate\Support\Str::plural('artículo', $event->articles->count()) }}
                        de {{ $event->articles->pluck('source.slug')->unique()->count() }}
                        {{ \Illuminate\Support\Str::plural('medio', $event->articles->pluck('source.slug')->unique()->count()) }}
                    </h2>

                    <ol class="flex flex-col">
                        @foreach ($event->articles as $article)
                            <li class="border-t border-edge py-4 first:border-t-0 first:pt-0">
                                <article class="flex flex-col gap-2">
                                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                                        <span class="font-semibold text-ink">{{ $article->source->name }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span class="tabular">{{ $article->published_at->translatedFormat('j \d\e M, H:i') }} h</span>
                                        @if ($article->author)
                                            <span aria-hidden="true">·</span>
                                            <span>{{ $article->author }}</span>
                                        @endif
                                    </p>

                                    <h3 class="font-heading text-lg font-extrabold tracking-tight">
                                        {{ $article->title }}
                                    </h3>

                                    <a
                                        href="{{ $article->url }}"
                                        target="_blank"
                                        rel="noopener noreferrer external"
                                        class="touch-target inline-flex w-fit items-center gap-1.5 text-sm font-semibold text-accent-strong hover:underline hover:underline-offset-4"
                                    >
                                        Leer en {{ $article->source->name }}
                                        <x-icon name="external-link" class="size-4" />
                                        <span class="sr-only">(se abre en una pestaña nueva)</span>
                                    </a>
                                </article>
                            </li>
                        @endforeach
                    </ol>

                    <p class="max-w-[62ch] text-xs text-muted">
                        {{ config('app.name') }} no reproduce el texto completo de las publicaciones.
                        Lo que se muestra es un resumen generado por IA con enlace a la fuente original.
                    </p>
                </section>
            </div>

            <aside class="flex flex-col gap-8">
                <section class="flex flex-col gap-3" aria-labelledby="menciones">
                    <h2 id="menciones" class="text-xs font-semibold tracking-[0.08em] text-muted uppercase">Menciones</h2>
                    <ul class="flex flex-col gap-2">
                        @foreach ($event->entities as $entity)
                            <li class="flex items-center gap-2 text-sm">
                                <x-icon :name="$entity->type === \App\Enums\EntityType::Company ? 'building' : 'user'" class="size-4 text-muted" />
                                <span class="font-semibold">{{ $entity->name }}</span>
                                <span class="sr-only">({{ $entity->type->label() }})</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                @if (! empty($event->tags))
                    <section class="flex flex-col gap-3" aria-labelledby="etiquetas">
                        <h2 id="etiquetas" class="text-xs font-semibold tracking-[0.08em] text-muted uppercase">Etiquetas</h2>
                        <ul class="flex flex-wrap gap-2">
                            @foreach ($event->tags as $tag)
                                <li class="bg-surface px-2.5 py-1 text-xs font-semibold">{{ $tag }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($related->isNotEmpty())
                    <section class="flex flex-col gap-3" aria-labelledby="relacionados">
                        <h2 id="relacionados" class="text-xs font-semibold tracking-[0.08em] text-muted uppercase">
                            Más de {{ $event->category->label() }}
                        </h2>
                        <ul class="flex flex-col gap-3">
                            @foreach ($related as $other)
                                <li class="border-t border-edge pt-3 first:border-t-0 first:pt-0">
                                    <a href="{{ route('events.show', $other->slug) }}" class="text-sm font-semibold hover:text-accent-strong">
                                        {{ $other->title }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </aside>
        </div>

    </div>
</x-layouts.app>
