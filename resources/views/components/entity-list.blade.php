@props([
    'entities',
])

@php
    $grouped = $entities->groupBy(fn ($entity) => $entity->type->value);
@endphp

@if ($entities->isNotEmpty())
    <dl {{ $attributes->merge(['class' => 'flex flex-col gap-2 text-sm sm:flex-row sm:gap-8']) }}>
        @foreach ($grouped as $group)
            @php $type = $group->first()->type; @endphp
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <dt class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    {{-- El tipo se distingue por icono, no por color --}}
                    <x-icon :name="$type === \App\Enums\EntityType::Company ? 'building' : 'user'" class="size-4" />
                    {{ $type->pluralLabel() }}
                </dt>
                <dd class="font-semibold">{{ $group->pluck('name')->join(', ', ' y ') }}</dd>
            </div>
        @endforeach
    </dl>
@endif
