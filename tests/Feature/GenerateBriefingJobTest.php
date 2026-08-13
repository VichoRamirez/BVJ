<?php

use App\Enums\BriefingEdition;
use App\Enums\RelevanceLevel;
use App\Jobs\GenerateBriefingJob;
use App\Models\Briefing;
use App\Models\Event;
use Carbon\CarbonImmutable;

/*
 * Publicación de la edición. Nada de esto llama a servicios externos.
 */

function chileNow(): CarbonImmutable
{
    return CarbonImmutable::now(config('newsscraper.briefing.timezone'));
}

beforeEach(function (): void {
    // Hora fija a las 20:00 de Chile: pasadas ambas ediciones del día. Sin esto,
    // el corte del período ("desde el briefing anterior, o 12 horas atrás")
    // depende de la hora real en que se corran los tests y de madrugada dejaría
    // fuera los acontecimientos recién creados.
    $this->travelTo(chileNow()->setTime(20, 0));
});

it('publica la edición con los acontecimientos más relevantes del período', function () {
    config(['newsscraper.briefing.events_per_edition' => 3]);

    $critical = Event::factory()->critical()->create(['first_seen_at' => now()->subHours(2)]);
    $high = Event::factory()->withRelevance(RelevanceLevel::High)->create(['first_seen_at' => now()->subHours(3)]);
    $medium = Event::factory()->withRelevance(RelevanceLevel::Medium)->create(['first_seen_at' => now()->subHours(4)]);
    Event::factory()->withRelevance(RelevanceLevel::Low)->create(['first_seen_at' => now()->subHours(5)]);

    dispatch_sync(new GenerateBriefingJob(BriefingEdition::Evening));

    $briefing = Briefing::query()->with('events')->sole();

    expect($briefing->edition)->toBe(BriefingEdition::Evening)
        // Baja queda fuera por el umbral de config, y el orden es por relevancia.
        ->and($briefing->events->pluck('id')->all())->toBe([$critical->id, $high->id, $medium->id]);
});

it('fecha la edición en la hora programada, no en la hora en que corrió', function () {
    Event::factory()->critical()->create(['first_seen_at' => now()->subHour()]);

    dispatch_sync(new GenerateBriefingJob(BriefingEdition::Morning));

    $publishedAt = Briefing::query()->sole()->published_at
        ->timezone(config('newsscraper.briefing.timezone'));

    expect($publishedAt->hour)->toBe(BriefingEdition::Morning->scheduledHour())
        ->and($publishedAt->minute)->toBe(0);
});

it('respeta el tope de acontecimientos por edición', function () {
    config(['newsscraper.briefing.events_per_edition' => 2]);

    Event::factory()->critical()->count(5)->create(['first_seen_at' => now()->subHour()]);

    dispatch_sync(new GenerateBriefingJob(BriefingEdition::Evening));

    expect(Briefing::query()->with('events')->sole()->events)->toHaveCount(2);
});

it('no publica una edición vacía', function () {
    dispatch_sync(new GenerateBriefingJob(BriefingEdition::Morning));

    expect(Briefing::query()->count())->toBe(0);
});

it('deja fuera los acontecimientos bajo el umbral de relevancia', function () {
    Event::factory()->withRelevance(RelevanceLevel::Low)->count(3)->create(['first_seen_at' => now()->subHour()]);

    dispatch_sync(new GenerateBriefingJob(BriefingEdition::Evening));

    expect(Briefing::query()->count())->toBe(0);
});

it('no repite en la tarde lo que ya salió en la mañana', function () {
    $morningEvent = Event::factory()->critical()->create([
        'first_seen_at' => chileNow()->setTime(6, 0),
    ]);

    dispatch_sync(new GenerateBriefingJob(BriefingEdition::Morning));

    $eveningEvent = Event::factory()->critical()->create([
        'first_seen_at' => chileNow()->setTime(12, 0),
    ]);

    dispatch_sync(new GenerateBriefingJob(BriefingEdition::Evening));

    $evening = Briefing::query()->where('edition', BriefingEdition::Evening)->with('events')->sole();

    expect($evening->events->pluck('id')->all())->toBe([$eveningEvent->id])
        ->and($evening->events->pluck('id'))->not->toContain($morningEvent->id);
});

it('es idempotente: reeditar la misma edición no crea otra fila', function () {
    Event::factory()->critical()->create(['first_seen_at' => now()->subHour()]);

    dispatch_sync(new GenerateBriefingJob(BriefingEdition::Evening));
    dispatch_sync(new GenerateBriefingJob(BriefingEdition::Evening));

    expect(Briefing::query()->count())->toBe(1);
});
