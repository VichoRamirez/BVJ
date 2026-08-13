<?php

use App\Enums\AnalysisStatus;
use App\Enums\RelevanceLevel;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Entity;
use App\Models\Event;

/*
 * Criterio de cierre de PLAN.md §2: las factories arman un acontecimiento
 * completo (Article + Analysis + Event) con sus relaciones resueltas.
 *
 * Las relaciones se cargan explícitamente porque Model::preventLazyLoading()
 * está activo fuera de producción.
 */

it('arma un acontecimiento cubierto por varios medios', function () {
    $created = Event::factory()->critical()->withArticles(3)->create();

    $event = Event::query()
        ->with(['articles.analysis', 'articles.source', 'articles.event'])
        ->find($created->id);

    expect($event->articles)->toHaveCount(3)
        ->and($event->articles->pluck('source_id')->unique())->toHaveCount(3)
        ->and($event->articles_count)->toBe(3);

    foreach ($event->articles as $article) {
        expect($article->analysis)->toBeInstanceOf(Analysis::class)
            ->and($article->analysis_status)->toBe(AnalysisStatus::Completed)
            ->and($article->event->is($event))->toBeTrue()
            ->and($article->source)->not->toBeNull();
    }
});

it('recalcula el puntaje de relevancia con el bonus por número de medios', function () {
    $event = Event::factory()->critical()->withArticles(2)->create()->fresh();

    expect($event->relevance_score)
        ->toBe(Event::scoreFor(RelevanceLevel::Critical, 2))
        ->toBeGreaterThan(Event::scoreFor(RelevanceLevel::Critical, 1));
});

it('sincroniza las entidades del acontecimiento desde sus artículos', function () {
    $event = Event::factory()->withArticles(2)->create();

    $event->load('articles');
    $event->articles->first()->entities()->attach(Entity::factory()->company()->create());

    $event->syncAggregatesFromArticles();

    expect(Event::query()->with('entities')->find($event->id)->entities)->toHaveCount(1);
});

it('deja los artículos sin analizar en estado pendiente', function () {
    $created = Article::factory()->pending()->create();

    $article = Article::query()->with('analysis')->find($created->id);

    expect($article->analysis_status)->toBe(AnalysisStatus::Pending)
        ->and($article->analysis)->toBeNull();
});
