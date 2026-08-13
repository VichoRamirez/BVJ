@props([
    'events',
    'numbered' => true,
])

{{-- Lista de acontecimientos separada por reglas de 2px: la costura es la
     estructura, no una tarjeta con sombra. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col']) }}>
    @foreach ($events as $event)
        @if (! $loop->first)
            <x-rule />
        @endif
        <x-event-card :event="$event" :position="$numbered ? $loop->iteration : null" />
    @endforeach
</div>
