<?php

use App\Enums\BriefingEdition;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Models\Briefing;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

it('admite una sola edición de cada tipo por día', function () {
    $day = CarbonImmutable::parse('2026-08-10');

    Briefing::factory()->morning()->on($day)->create();

    expect(fn () => Briefing::factory()->morning()->on($day)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('convive la edición de la mañana con la de la tarde del mismo día', function () {
    $day = CarbonImmutable::parse('2026-08-10');

    Briefing::factory()->morning()->on($day)->create();
    Briefing::factory()->evening()->on($day)->create();

    expect(Briefing::whereDate('published_on', $day)->count())->toBe(2);
});

it('devuelve los acontecimientos en el orden del pivote', function () {
    $briefing = Briefing::factory()->morning()->create();

    $first = Event::factory()->create(['title' => 'Titular de la edición']);
    $second = Event::factory()->create(['title' => 'Segundo acontecimiento']);

    // Se enganchan al revés a propósito: el orden lo tiene que dar `position`.
    $briefing->events()->attach([
        $second->id => ['position' => 2],
        $first->id => ['position' => 1],
    ]);

    expect(Briefing::query()->with('events')->find($briefing->id)->events->pluck('title')->all())
        ->toBe(['Titular de la edición', 'Segundo acontecimiento']);
});

it('trae las otras ediciones del mismo día ordenadas por hora', function () {
    $day = CarbonImmutable::parse('2026-08-10');

    $evening = Briefing::factory()->evening()->on($day)->create();
    Briefing::factory()->morning()->on($day)->create();
    Briefing::factory()->morning()->on($day->subDay())->create();

    $siblings = Briefing::query()->sameDayAs($evening)->get();

    expect($siblings)->toHaveCount(2)
        ->and($siblings->first()->edition)->toBe(BriefingEdition::Morning);
});

it('cuenta acontecimientos por categoría con claves de texto', function () {
    // El contador solo cuenta lo ya publicado, así que los acontecimientos van
    // dentro de una edición cuya hora de publicación ya pasó.
    $briefing = Briefing::factory()->create([
        'edition' => BriefingEdition::Morning,
        'published_on' => now(),
        'published_at' => now()->subHour(),
    ]);

    $events = collect([
        ...Event::factory()->count(2)->ofCategory(NewsCategory::Monetary)->create(),
        Event::factory()->ofCategory(NewsCategory::Markets)->create(),
    ]);

    $briefing->events()->attach(
        $events->mapWithKeys(fn (Event $event, int $index): array => [$event->id => ['position' => $index + 1]])->all()
    );

    $counts = Event::categoryCounts();

    expect($counts[NewsCategory::Monetary->value])->toBe(2)
        ->and($counts[NewsCategory::Markets->value])->toBe(1)
        ->and($counts->get(NewsCategory::Technology->value))->toBeNull();
});

it('no cuenta acontecimientos que ninguna edición publicada incluye', function () {
    Event::factory()->count(2)->ofCategory(NewsCategory::Monetary)->create();

    expect(Event::categoryCounts()->get(NewsCategory::Monetary->value))->toBeNull();
});

it('ordena del más relevante al menos relevante', function () {
    Event::factory()->withRelevance(RelevanceLevel::Low)->create(['title' => 'Baja']);
    Event::factory()->withRelevance(RelevanceLevel::Critical)->create(['title' => 'Crítica']);
    Event::factory()->withRelevance(RelevanceLevel::Medium)->create(['title' => 'Media']);

    expect(Event::query()->mostRelevant()->pluck('title')->all())
        ->toBe(['Crítica', 'Media', 'Baja']);
});
