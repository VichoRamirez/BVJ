<?php

use App\Enums\EntityType;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Jobs\ClusterArticlesJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Source;

/*
 * Agrupación de artículos en acontecimientos. El motor ya está probado sin base
 * de datos en tests/Unit; acá se prueba el puente con Eloquent.
 */

/**
 * Artículo analizado, de su propia fuente, listo para agrupar.
 */
function analyzedArticle(
    string $title,
    NewsCategory $category = NewsCategory::Monetary,
    RelevanceLevel $relevance = RelevanceLevel::High,
    int $hoursAgo = 2,
    array $entities = ['Banco Central'],
): Article {
    $article = Article::factory()
        ->for(Source::factory())
        ->analyzed()
        ->create([
            'title' => $title,
            'published_at' => now()->subHours($hoursAgo),
        ]);

    $article->analysis->update([
        'category' => $category,
        'relevance' => $relevance,
    ]);

    foreach ($entities as $name) {
        $article->entities()->attach(
            Entity::firstOrCreateFor(EntityType::Company, $name)->id
        );
    }

    return $article;
}

it('agrupa en un acontecimiento los artículos de distintos medios sobre lo mismo', function () {
    $first = analyzedArticle('Banco Central mantiene la tasa de política monetaria en 4,75%');
    $second = analyzedArticle('Banco Central mantiene la tasa de política monetaria');

    dispatch_sync(new ClusterArticlesJob);

    $event = Event::query()->sole();

    expect($event->articles_count)->toBe(2)
        ->and($event->category)->toBe(NewsCategory::Monetary)
        ->and($first->fresh()->event_id)->toBe($event->id)
        ->and($second->fresh()->event_id)->toBe($event->id);
});

it('no mezcla acontecimientos de categorías distintas', function () {
    analyzedArticle('Banco Central mantiene la tasa de política monetaria', NewsCategory::Monetary);
    analyzedArticle('Banco Central mantiene la tasa de política monetaria', NewsCategory::Companies);

    dispatch_sync(new ClusterArticlesJob);

    expect(Event::query()->count())->toBe(2);
});

it('toma la relevancia más alta del grupo y suma el bonus por fuentes distintas', function () {
    analyzedArticle('Cobre supera los cinco dólares la libra', NewsCategory::Commodities, RelevanceLevel::Medium);
    analyzedArticle('Cobre supera los cinco dólares la libra', NewsCategory::Commodities, RelevanceLevel::Critical);

    dispatch_sync(new ClusterArticlesJob);

    $event = Event::query()->sole();

    expect($event->relevance)->toBe(RelevanceLevel::Critical)
        ->and($event->relevance_score)->toBe(Event::scoreFor(RelevanceLevel::Critical, 2));
});

it('deja fuera los artículos anteriores a la ventana', function () {
    analyzedArticle('Cobre supera los cinco dólares la libra', hoursAgo: 2);
    analyzedArticle('Imacec de julio sorprende al alza y crece 3,2%', hoursAgo: 80);

    dispatch_sync(new ClusterArticlesJob(windowHours: 24));

    expect(Event::query()->count())->toBe(1)
        ->and(Event::query()->sole()->title)->toContain('Cobre');
});

it('es idempotente: volver a correrlo no duplica acontecimientos', function () {
    analyzedArticle('Banco Central mantiene la tasa de política monetaria en 4,75%');
    analyzedArticle('Banco Central mantiene la tasa de política monetaria');

    dispatch_sync(new ClusterArticlesJob);
    dispatch_sync(new ClusterArticlesJob);

    expect(Event::query()->count())->toBe(1)
        ->and(Event::query()->sole()->articles_count)->toBe(2);
});

it('suma al acontecimiento existente el artículo que llega después', function () {
    analyzedArticle('Banco Central mantiene la tasa de política monetaria en 4,75%', hoursAgo: 5);
    dispatch_sync(new ClusterArticlesJob);

    $firstSeenAt = Event::query()->sole()->first_seen_at;

    analyzedArticle('Banco Central mantiene la tasa de política monetaria', hoursAgo: 1);
    dispatch_sync(new ClusterArticlesJob);

    $event = Event::query()->sole();

    expect($event->articles_count)->toBe(2)
        // El acontecimiento no rejuvenece: conserva la primera vez que se vio.
        ->and($event->first_seen_at->timestamp)->toBe($firstSeenAt->timestamp);
});

it('copia el resumen y la importancia del artículo representativo', function () {
    $article = analyzedArticle('Cobre supera los cinco dólares la libra', NewsCategory::Commodities, RelevanceLevel::Critical);
    $article->analysis->update([
        'summary' => 'El metal cerró en su nivel más alto del año.',
        'importance_explanation' => 'El cobre explica la mitad de las exportaciones chilenas.',
    ]);

    dispatch_sync(new ClusterArticlesJob);

    $event = Event::query()->sole();

    expect($event->summary)->toBe('El metal cerró en su nivel más alto del año.')
        ->and($event->importance)->toBe('El cobre explica la mitad de las exportaciones chilenas.');
});

it('ignora los artículos sin análisis', function () {
    Article::factory()->pending()->create(['published_at' => now()->subHour()]);

    dispatch_sync(new ClusterArticlesJob);

    expect(Event::query()->count())->toBe(0)
        ->and(Analysis::query()->count())->toBe(0);
});
