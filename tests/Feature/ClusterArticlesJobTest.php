<?php

use App\Enums\AnalysisStatus;
use App\Enums\EntityType;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Jobs\ClusterArticlesJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Briefing;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Source;
use App\Models\Tag;
use App\Services\Clustering\ArticleClusterer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

function clusterArticle(array $attributes = [], array $analysis = [], array $entities = [], array $tags = []): Article
{
    $article = Article::factory()->create(array_merge([
        'title' => 'Banco central anuncia nuevas medidas',
        'published_at' => CarbonImmutable::parse('2026-08-12 12:00:00'),
        'analysis_status' => AnalysisStatus::Completed,
    ], $attributes));
    Analysis::factory()->for($article)->create(array_merge([
        'category' => NewsCategory::Economy,
        'relevance' => RelevanceLevel::High,
        'summary' => 'Resumen líder',
        'importance_explanation' => 'Importancia líder',
    ], $analysis));
    $article->entities()->sync(collect($entities)->map(fn (string $name): int => Entity::firstOrCreateFor(EntityType::Company, $name)->id)->all());
    $article->tags()->sync(collect($tags)->map(fn (string $name): int => Tag::factory()->create(['name' => $name])->id)->all());

    return $article->load(['analysis', 'entities', 'tags', 'source']);
}

it('persists singleton clusters with deterministic identity', function () {
    $article = clusterArticle(['title' => 'Noticia única']);

    (new ClusterArticlesJob(cutoff: CarbonImmutable::parse('2026-08-12 13:00:00')))->handle(app(ArticleClusterer::class));

    $event = Event::firstOrFail();
    expect($event->cluster_key)->toBe(hash('sha256', $article->id.':'.$article->url_hash))
        ->and($event->articles_count)->toBe(1)
        ->and($article->fresh()->event_id)->toBe($event->id);
});

it('persists exact leader aggregates and the entity union', function () {
    config()->set('newsscraper.clustering.shared_entities_minimum', 1);
    $leader = clusterArticle(['title' => 'Líder de la noticia', 'source_id' => Source::factory()->create()->id, 'published_at' => '2026-08-12 10:00:00'], ['relevance' => RelevanceLevel::Critical], ['Banco Central'], ['líder']);
    $other = clusterArticle(['title' => 'Banco central anuncia medidas importantes', 'source_id' => Source::factory()->create()->id, 'published_at' => '2026-08-12 11:00:00'], ['relevance' => RelevanceLevel::Low], ['Banco Central', 'Ministerio'], ['otro']);

    (new ClusterArticlesJob(cutoff: CarbonImmutable::parse('2026-08-12 13:00:00')))->handle(app(ArticleClusterer::class));

    $event = Event::with('entities')->firstOrFail();
    expect($event->title)->toBe($leader->title)
        ->and($event->summary)->toBe('Resumen líder')
        ->and($event->importance)->toBe('Importancia líder')
        ->and($event->tags)->toBe(['líder'])
        ->and($event->articles_count)->toBe(2)
        ->and($event->first_seen_at->toDateTimeString())->toBe('2026-08-12 10:00:00')
        ->and($event->relevance_score)->toBe(Event::scoreFor(RelevanceLevel::Critical, 2))
        ->and($event->entities->pluck('name')->sort()->values()->all())->toBe(['Banco Central', 'Ministerio']);
});

it('excludes ineligible articles and is idempotent', function () {
    $eligible = clusterArticle();
    clusterArticle(['analysis_status' => AnalysisStatus::Pending]);
    clusterArticle(['published_at' => null]);
    clusterArticle(['published_at' => now()->subDays(4)]);
    $assigned = clusterArticle(['event_id' => Event::factory()->create()->id]);

    $job = new ClusterArticlesJob(cutoff: CarbonImmutable::parse('2026-08-12 13:00:00'));
    $job->handle(app(ArticleClusterer::class));
    $job->handle(app(ArticleClusterer::class));

    expect(Event::whereNotNull('cluster_key')->count())->toBe(1)
        ->and($eligible->fresh()->event_id)->not->toBeNull()
        ->and($assigned->fresh()->event_id)->not->toBeNull();
});

it('excludes completed articles without analysis', function () {
    Article::factory()->create([
        'analysis_status' => AnalysisStatus::Completed,
        'published_at' => '2026-08-12 12:00:00',
    ]);

    (new ClusterArticlesJob(cutoff: CarbonImmutable::parse('2026-08-12 13:00:00')))->handle(app(ArticleClusterer::class));

    expect(Event::whereNotNull('cluster_key')->count())->toBe(0);
});

it('keeps assigned singleton immutable and creates a separate event for later related articles', function () {
    config()->set('newsscraper.clustering.shared_entities_minimum', 1);
    $first = clusterArticle(['title' => 'Banco central anuncia medidas'], [], ['Banco Central']);
    (new ClusterArticlesJob(cutoff: CarbonImmutable::parse('2026-08-12 13:00:00')))->handle(app(ArticleClusterer::class));
    $existing = Event::with(['entities', 'articles'])->firstOrFail();
    $before = $existing->only(['title', 'summary', 'importance', 'category', 'relevance', 'tags', 'first_seen_at', 'articles_count']);

    $later = clusterArticle(['title' => 'Banco central anuncia medidas'], [], ['Banco Central'], []);
    (new ClusterArticlesJob(cutoff: CarbonImmutable::parse('2026-08-12 14:00:00')))->handle(app(ArticleClusterer::class));

    expect(Event::whereNotNull('cluster_key')->count())->toBe(2)
        ->and($existing->fresh()->only(array_keys($before)))->toEqual($before)
        ->and($later->fresh()->event_id)->not->toBe($first->fresh()->event_id);
});

it('uses the locked fresh snapshot for persistence', function () {
    $article = clusterArticle(['title' => 'Título original']);
    $job = new class extends ClusterArticlesJob
    {
        protected function loadMembersForPersistence(array $memberIds): Collection
        {
            $members = parent::loadMembersForPersistence($memberIds);
            $members->first()->update(['title' => 'Título actualizado', 'analysis_status' => AnalysisStatus::Completed]);

            return parent::loadMembersForPersistence($memberIds);
        }
    };

    $job->handle(app(ArticleClusterer::class));

    expect(Event::firstOrFail()->title)->toBe('Título actualizado');
});

it('rolls back event, article assignment and entities when synchronization fails', function () {
    $article = clusterArticle();
    $job = new class extends ClusterArticlesJob
    {
        protected function syncEntities(Event $event, Collection $members): void
        {
            throw new QueryException('sqlite', 'forced', [], new RuntimeException('forced'));
        }
    };

    expect(fn () => $job->handle(app(ArticleClusterer::class)))->toThrow(QueryException::class);
    expect(Event::whereNotNull('cluster_key')->count())->toBe(0)
        ->and($article->fresh()->event_id)->toBeNull();
});

it('does not persist partially when maxArticles is exceeded', function () {
    config()->set('newsscraper.clustering.max_articles', 1);
    clusterArticle();
    clusterArticle(['title' => 'Otra noticia']);

    expect(fn () => (new ClusterArticlesJob(cutoff: CarbonImmutable::parse('2026-08-12 13:00:00')))->handle(app(ArticleClusterer::class)))
        ->toThrow(RuntimeException::class);
    expect(Event::whereNotNull('cluster_key')->count())->toBe(0);
});

it('uses the shared lock and structured logs without sensitive fields', function () {
    Log::spy();
    $job = new ClusterArticlesJob;
    $middleware = $job->middleware()[0];

    expect($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($job->timeout)->toBeGreaterThan(0);
    $job->handle(app(ArticleClusterer::class));
    Log::assertLogged('info', fn (string $message, array $context): bool => $message === 'Article clustering considered' && array_key_exists('considered', $context));
});

it('does not modify an existing briefing event', function () {
    $event = Event::factory()->withArticles(1)->create(['cluster_key' => hash('sha256', 'existing')]);
    $briefing = Briefing::factory()->create();
    $briefing->events()->attach($event, ['position' => 1]);
    $before = $event->fresh()->load(['articles', 'entities', 'briefings']);
    $beforeSnapshot = [
        'attributes' => $before->getAttributes(),
        'article_ids' => $before->articles->pluck('id')->all(),
        'entity_ids' => $before->entities->pluck('id')->all(),
        'briefings' => $before->briefings->map(fn (Briefing $briefing): array => [
            'id' => $briefing->id,
            'position' => $briefing->pivot->position,
        ])->all(),
    ];
    clusterArticle();

    (new ClusterArticlesJob(cutoff: CarbonImmutable::parse('2026-08-12 13:00:00')))->handle(app(ArticleClusterer::class));

    $after = $event->fresh()->load(['articles', 'entities', 'briefings']);
    expect([
        'attributes' => $after->getAttributes(),
        'article_ids' => $after->articles->pluck('id')->all(),
        'entity_ids' => $after->entities->pluck('id')->all(),
        'briefings' => $after->briefings->map(fn (Briefing $briefing): array => [
            'id' => $briefing->id,
            'position' => $briefing->pivot->position,
        ])->all(),
    ])->toEqual($beforeSnapshot);
});

it('rethrows a unique collision that is not the cluster key race', function () {
    $article = clusterArticle(['title' => 'Colisión de slug']);
    $clusterKey = hash('sha256', $article->id.':'.$article->url_hash);
    Event::factory()->create([
        'cluster_key' => hash('sha256', 'different'),
        'slug' => 'colision-de-slug-'.substr($clusterKey, 0, 10),
    ]);

    expect(fn () => (new ClusterArticlesJob(cutoff: CarbonImmutable::parse('2026-08-12 13:00:00')))->handle(app(ArticleClusterer::class)))
        ->toThrow(UniqueConstraintViolationException::class);
    expect($article->fresh()->event_id)->toBeNull()
        ->and(Event::where('cluster_key', $clusterKey)->exists())->toBeFalse();
});
