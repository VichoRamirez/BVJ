<?php

use App\Enums\NewsCategory;
use App\Models\Briefing;
use App\Models\Event;

/*
 * Smoke de las seis rutas del frontend contra la base de datos sembrada.
 * Ninguna toca la red. Con Model::preventLazyLoading() activo, estos tests son
 * además el detector de N+1: si a un controller le falta un eager load,
 * explotan con LazyLoadingViolationException.
 */

it('renderiza las rutas principales', function (string $url) {
    seedDemo();

    $this->get($url)
        ->assertSuccessful()
        ->assertSee('NewsScraper', escape: false);
})->with([
    'portada' => '/',
    'histórico de briefings' => '/briefings',
    'mercados' => '/mercados',
]);

it('muestra el último briefing en la portada', function () {
    seedDemo();

    $briefing = Briefing::query()->with('events')->latest('published_at')->first();

    $this->get('/')
        ->assertSuccessful()
        ->assertSee($briefing->edition->label())
        ->assertSee($briefing->events->first()->title)
        ->assertSee('Los resúmenes de esta página los genera un modelo de lenguaje');
});

it('muestra el estado vacío cuando no hay ningún briefing publicado', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Todavía no hay briefing publicado');
});

it('permite forzar el estado vacío con ?vacio=1', function () {
    seedDemo();

    $this->get('/?vacio=1')
        ->assertSuccessful()
        ->assertSee('Todavía no hay briefing publicado');
});

it('abre una edición concreta', function () {
    seedDemo();

    $briefing = Briefing::query()->with('events')->orderBy('published_at')->first();

    $this->get("/briefings/{$briefing->id}")
        ->assertSuccessful()
        ->assertSee($briefing->events->first()->title);
});

it('devuelve 404 para una edición inexistente', function () {
    $this->get('/briefings/9999')->assertNotFound();
});

it('muestra el detalle de un acontecimiento con sus fuentes y el enlace original', function () {
    seedDemo();

    $event = Event::query()->with('articles.source')->mostRelevant()->first();
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
    seedDemo();

    $category = NewsCategory::Monetary;

    $response = $this->get("/categorias/{$category->value}")->assertSuccessful();

    $events = Event::query()->where('category', $category)->get();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        $response->assertSee($event->title);
    }
});

it('devuelve 404 para una categoría desconocida', function () {
    $this->get('/categorias/futbol')->assertNotFound();
});

it('lista los instrumentos de mercado con su variación', function () {
    seedDemo();

    $this->get('/mercados')
        ->assertSuccessful()
        ->assertSee('IPSA')
        ->assertSee('USD / CLP', escape: false)
        ->assertSee('Cobre');
});

it('muestra el estado vacío de mercados cuando no hay capturas', function () {
    $this->get('/mercados')
        ->assertSuccessful()
        ->assertSee('Todavía no hay datos de mercado');
});
