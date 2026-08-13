<?php

use App\Enums\BriefingEdition;
use App\Enums\NewsCategory;
use App\Support\DemoContent;

/*
 * Smoke de las seis rutas del frontend contra los datos de demostración.
 * Ninguna toca la red ni la base de datos. Cuando entren los modelos reales,
 * estos tests se reapuntan a factories sin cambiar las aserciones.
 */

it('renderiza las rutas principales', function (string $url) {
    $this->get($url)
        ->assertSuccessful()
        ->assertSee('News', escape: false)
        ->assertSee('Scraper', escape: false);
})->with([
    'portada' => '/',
    'histórico de briefings' => '/briefings',
    'mercados' => '/mercados',
]);

it('muestra el último briefing en la portada', function () {
    $briefing = DemoContent::latestBriefing();

    $this->get('/')
        ->assertSuccessful()
        ->assertSee($briefing->edition->label())
        ->assertSee($briefing->events->first()->title)
        ->assertSee('Los resúmenes de esta página los genera un modelo de lenguaje');
});

it('no muestra una edición futura como último briefing', function () {
    $morning = DemoContent::briefings()
        ->first(fn (object $briefing): bool => $briefing->edition === BriefingEdition::Morning);

    $this->travelTo($morning->published_at->addHour());

    expect(DemoContent::latestBriefing()->id)->toBe($morning->id);
});

it('muestra el estado vacío cuando no hay briefing', function () {
    $this->get('/?vacio=1')
        ->assertSuccessful()
        ->assertSee('Todavía no hay briefing publicado');
});

it('abre una edición concreta', function () {
    $briefing = DemoContent::briefings()->last();

    $this->get("/briefings/{$briefing->id}")
        ->assertSuccessful()
        ->assertSee($briefing->events->first()->title);
});

it('devuelve 404 para una edición inexistente', function () {
    $this->get('/briefings/9999')->assertNotFound();
});

it('muestra el detalle de un acontecimiento con sus fuentes y el enlace original', function () {
    $event = DemoContent::events()->first();
    $article = $event->articles->first();

    $this->get("/eventos/{$event->slug}")
        ->assertSuccessful()
        ->assertSee($event->title)
        ->assertSee($event->importance)
        ->assertSee($article->source->name)
        ->assertSee($article->url, escape: false);
});

it('devuelve 404 para un acontecimiento inexistente', function () {
    $this->get('/eventos/no-existe')->assertNotFound();
});

it('filtra acontecimientos por categoría', function () {
    $category = NewsCategory::Monetary;

    $response = $this->get("/categorias/{$category->slug()}")->assertSuccessful();

    foreach (DemoContent::eventsByCategory($category) as $event) {
        $response->assertSee($event->title);
    }
});

it('devuelve 404 para una categoría desconocida', function () {
    $this->get('/categorias/futbol')->assertNotFound();
});

it('lista los instrumentos de mercado con su variación', function () {
    $this->get('/mercados')
        ->assertSuccessful()
        ->assertSee('IPSA')
        ->assertSee('USD / CLP', escape: false)
        ->assertSee('Cobre');
});
