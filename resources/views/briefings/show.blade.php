<x-layouts.app :title="$briefing->edition->label().' · '.$briefing->published_on->translatedFormat('j \d\e F')">
    <div class="mx-auto w-full max-w-6xl px-5 sm:px-8">

        <div class="flex flex-col gap-8 pt-10 pb-6">
            <a href="{{ route('briefings.index') }}" class="inline-flex w-fit items-center gap-1.5 text-sm font-semibold text-muted hover:text-accent-strong">
                <x-icon name="chevron-right" class="size-4 rotate-180" />
                Todas las ediciones
            </a>

            <x-briefing-header :briefing="$briefing" :siblings="$siblings" />
            <x-ai-disclosure />
        </div>

        <x-rule />

        <x-event-list :events="$briefing->events" />

    </div>
</x-layouts.app>
