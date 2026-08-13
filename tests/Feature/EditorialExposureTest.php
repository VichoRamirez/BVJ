<?php

use App\Enums\BriefingEdition;
use App\Enums\NewsCategory;
use App\Models\Briefing;
use App\Models\Event;

/*
 * Nada se filtra antes de su hora de publicación.
 *
 * Un acontecimiento existe en la base desde que el clustering lo crea, y una
 * edición desde que el pipeline la arma. Ninguna de las dos cosas lo hace
 * público: lo público es lo que ya salió en una edición publicada.
 */

/**
 * Un acontecimiento dentro de una edición con la hora de publicación dada.
 */
function eventInBriefingPublishedAt(mixed $publishedAt, ?NewsCategory $category = null): Event
{
    // Cada llamada usa un día editorial distinto: `briefings` tiene un unique
    // sobre (published_on, edition) y varias pruebas necesitan dos ediciones.
    static $day = 0;

    $event = Event::factory()->critical()->create([
        'category' => $category ?? NewsCategory::Monetary,
    ]);

    Briefing::factory()->create([
        'edition' => BriefingEdition::Evening,
        'published_on' => now()->subDays($day++),
        'published_at' => $publishedAt,
    ])->events()->attach($event->id, ['position' => 1]);

    return $event;
}

it('devuelve 404 en una edición que todavía no se publica', function () {
    $future = Briefing::factory()->create([
        'edition' => BriefingEdition::Evening,
        'published_on' => now(),
        'published_at' => now()->addHours(3),
    ]);

    $this->get("/briefings/{$future->id}")->assertNotFound();
});

it('deja abrir una edición ya publicada', function () {
    $published = Briefing::factory()->create([
        'edition' => BriefingEdition::Morning,
        'published_on' => now(),
        'published_at' => now()->subHour(),
    ]);

    $this->get("/briefings/{$published->id}")->assertSuccessful();
});

it('no lista una edición futura en el histórico', function () {
    $future = Briefing::factory()->create([
        'edition' => BriefingEdition::Evening,
        'published_on' => now(),
        'published_at' => now()->addHours(3),
    ]);

    $this->get('/briefings')->assertSuccessful()->assertDontSee("/briefings/{$future->id}");
});

it('devuelve 404 en un acontecimiento que ninguna edición publicada incluye', function () {
    $event = eventInBriefingPublishedAt(now()->addHours(3));

    $this->get("/eventos/{$event->slug}")->assertNotFound();
});

it('devuelve 404 en un acontecimiento que no está en ninguna edición', function () {
    // Recién salido del clustering: existe, pero todavía no se publicó.
    $event = Event::factory()->critical()->create();

    $this->get("/eventos/{$event->slug}")->assertNotFound();
});

it('deja abrir un acontecimiento ya publicado', function () {
    $event = eventInBriefingPublishedAt(now()->subHour());

    $this->get("/eventos/{$event->slug}")->assertSuccessful()->assertSee($event->title);
});

it('no filtra acontecimientos futuros en la vista de categoría', function () {
    $published = eventInBriefingPublishedAt(now()->subHour(), NewsCategory::Monetary);
    $upcoming = eventInBriefingPublishedAt(now()->addHours(3), NewsCategory::Monetary);

    $this->get('/categorias/'.NewsCategory::Monetary->slug())
        ->assertSuccessful()
        ->assertSee($published->title)
        ->assertDontSee($upcoming->title);
});

it('no cuenta acontecimientos futuros en el contador de categorías', function () {
    eventInBriefingPublishedAt(now()->subHour(), NewsCategory::Monetary);
    eventInBriefingPublishedAt(now()->addHours(3), NewsCategory::Monetary);

    expect(Event::categoryCounts()[NewsCategory::Monetary->value])->toBe(1);
});

it('no sugiere acontecimientos futuros como relacionados', function () {
    $event = eventInBriefingPublishedAt(now()->subHour(), NewsCategory::Monetary);
    $upcoming = eventInBriefingPublishedAt(now()->addHours(3), NewsCategory::Monetary);

    $this->get("/eventos/{$event->slug}")
        ->assertSuccessful()
        ->assertDontSee($upcoming->title);
});
