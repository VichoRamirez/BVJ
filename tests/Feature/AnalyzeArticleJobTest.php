<?php

use App\Contracts\NewsAnalyzer;
use App\Data\AnalysisResult;
use App\Data\NewsArticleInput;
use App\Enums\AnalysisStatus;
use App\Enums\EntityType;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Jobs\AnalyzeArticleJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Entity;
use App\Models\Tag;
use App\Services\Clustering\EntityNormalizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

function analysisResult(array $overrides = []): AnalysisResult
{
    return new AnalysisResult(
        summary: 'Resumen persistido',
        category: NewsCategory::Economy,
        relevance: RelevanceLevel::High,
        companies: [' Codelco S.A. ', 'CODELCO'],
        people: ['Ana Pérez'],
        tags: [' Economía ', 'economia'],
        importanceExplanation: 'Explicación persistida',
        provider: 'fake',
        model: 'test-model',
        schemaVersion: '1.0',
        rawResponse: [
            'content' => 'fake-analysis',
            'payload' => array_replace(['summary' => 'Resumen persistido'], $overrides),
        ],
    );
}

function bindAnalyzer(?Throwable $exception = null): void
{
    app()->instance(NewsAnalyzer::class, new class($exception) implements NewsAnalyzer
    {
        public function __construct(private readonly ?Throwable $exception) {}

        public function analyze(NewsArticleInput $article): AnalysisResult
        {
            if ($this->exception !== null) {
                throw $this->exception;
            }

            return analysisResult(['title' => $article->title]);
        }
    });
}

test('analyses and persists the complete result atomically', function () {
    bindAnalyzer();
    $article = Article::factory()->pending()->create();

    (new AnalyzeArticleJob($article->id))->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));

    $article->refresh();
    expect($article->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($article->analysis)->toBeInstanceOf(Analysis::class)
        ->and($article->analysis->raw_response['payload']['summary'])->toBe('Resumen persistido')
        ->and($article->entities)->toHaveCount(2)
        ->and($article->tags)->toHaveCount(1)
        ->and(Entity::where('type', EntityType::Company)->where('slug', 'codelco')->count())->toBe(1)
        ->and(Tag::where('slug', 'economia')->count())->toBe(1);
});

test('is idempotent and completed articles are a no-op', function () {
    bindAnalyzer();
    $article = Article::factory()->pending()->create();
    $job = new AnalyzeArticleJob($article->id);

    $job->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));
    $counts = [Analysis::count(), Entity::count(), Tag::count()];
    $job->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));

    expect([Analysis::count(), Entity::count(), Tag::count()])->toBe($counts)
        ->and($article->fresh()->analysis_status)->toBe(AnalysisStatus::Completed);
});

test('marks processing before analysis and exposes per article overlap identity', function () {
    bindAnalyzer();
    $article = Article::factory()->pending()->create();
    $job = new AnalyzeArticleJob($article->id);

    expect($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and(serialize($job->middleware()[0]))->toContain((string) $article->id)
        ->and($job->tries)->toBeGreaterThan(1);

    $job->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));

    expect($article->fresh()->analysis_attempts)->toBe(1)
        ->and($article->fresh()->analysis_started_at)->toBeNull()
        ->and($article->fresh()->analysis_completed_at)->not->toBeNull();
});

test('keeps queue retry visibility beyond the job timeout', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');
    $timeout = (new AnalyzeArticleJob(1))->timeout;
    $overlapTtl = (int) config('newsscraper.ai.overlap_ttl');

    expect($retryAfter)->toBeGreaterThan($timeout + 30)
        ->and($overlapTtl)->toBeGreaterThan($timeout + 30);
});

test('dispatches the article id through Laravel queue APIs', function () {
    Queue::fake();
    $article = Article::factory()->pending()->create();

    AnalyzeArticleJob::dispatch($article->id);

    Queue::assertPushed(AnalyzeArticleJob::class, fn (AnalyzeArticleJob $job): bool => $job->articleId === $article->id);
});

test('marks final failure without creating partial analysis data', function () {
    Log::spy();
    $article = Article::factory()->pending()->create([
    ]);
    $exception = new RuntimeException('analyzer failed');
    $job = new AnalyzeArticleJob($article->id);
    $article->update([
        'analysis_status' => AnalysisStatus::Processing,
        'analysis_run_id' => $job->runId,
        'analysis_started_at' => now(),
        'analysis_completed_at' => now()->subMinute(),
    ]);

    $job->failed($exception);

    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Failed)
        ->and($article->fresh()->analysis_error)->toBe('No fue posible completar el análisis.')
        ->and($article->fresh()->analysis_started_at)->toBeNull()
        ->and($article->fresh()->analysis_completed_at)->toBeNull()
        ->and(Analysis::count())->toBe(0);
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Article analysis exhausted retries'
        && $context['article_id'] === $article->id);
});

test('rolls back analysis and pivots when persistence fails', function () {
    bindAnalyzer();
    $article = Article::factory()->pending()->create();
    $job = new AnalyzeArticleJob($article->id);
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains(strtolower($query->sql), 'insert into "entities"')) {
            throw new RuntimeException('persistence failed');
        }
    });

    $this->expectException(Throwable::class);
    $job->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));

    expect(Analysis::count())->toBe(0)
        ->and($article->fresh()->entities)->toHaveCount(0)
        ->and($article->fresh()->analysis_status)->toBe(AnalysisStatus::Processing);
});

test('serializes only the article id and restores the current article data', function () {
    $article = Article::factory()->create(['title' => 'Original title']);
    $serialized = serialize(new AnalyzeArticleJob($article->id));
    $article->update(['title' => 'Current title']);
    $restored = unserialize($serialized);

    expect($serialized)->not->toContain('Original title')
        ->not->toContain($article->url)
        ->and($restored->articleId)->toBe($article->id)
        ->and(Article::findOrFail($restored->articleId)->title)->toBe('Current title');
});

test('recovers a processing article and clears stale metadata on completion', function () {
    bindAnalyzer();
    $article = Article::factory()->create([
        'analysis_status' => AnalysisStatus::Processing,
        'analysis_started_at' => now()->subHour(),
        'analysis_completed_at' => now()->subHour(),
        'analysis_error' => 'stale error',
    ]);

    (new AnalyzeArticleJob($article->id))->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));

    $article->refresh();
    expect($article->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($article->analysis_error)->toBeNull()
        ->and($article->analysis_started_at)->toBeNull()
        ->and($article->analysis_completed_at)->not->toBeNull();
});

test('does not reprocess a recent processing article', function () {
    bindAnalyzer();
    $article = Article::factory()->create([
        'analysis_status' => AnalysisStatus::Processing,
        'analysis_started_at' => now()->subSeconds(10),
        'analysis_attempts' => 2,
    ]);

    (new AnalyzeArticleJob($article->id))->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));

    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Processing)
        ->and($article->fresh()->analysis_attempts)->toBe(2)
        ->and(Analysis::count())->toBe(0);
});

test('redispatch recovers a stale processing article and increments attempts', function () {
    bindAnalyzer();
    $article = Article::factory()->create([
        'analysis_status' => AnalysisStatus::Processing,
        'analysis_started_at' => now()->subSeconds(config('newsscraper.ai.processing_stale_after') + 1),
        'analysis_attempts' => 2,
        'analysis_error' => 'stale failure',
    ]);

    (new AnalyzeArticleJob($article->id))->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));

    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($article->fresh()->analysis_attempts)->toBe(3)
        ->and($article->fresh()->analysis_error)->toBeNull();
});

test('a stale failed callback cannot downgrade a completed article', function () {
    Log::spy();
    $article = Article::factory()->analyzed()->create([
        'analysis_status' => AnalysisStatus::Completed,
        'analysis_completed_at' => now(),
    ]);

    (new AnalyzeArticleJob($article->id))->failed(new RuntimeException('old attempt secret'));

    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($article->fresh()->analysis_error)->toBeNull();
    Log::shouldNotHaveReceived('error');
});

test('leaves processing for a retry and completes on the next execution', function () {
    $article = Article::factory()->pending()->create();
    $job = new AnalyzeArticleJob($article->id);
    $calls = 0;
    $analyzer = new class($calls) implements NewsAnalyzer
    {
        public function __construct(private int &$calls) {}

        public function analyze(NewsArticleInput $article): AnalysisResult
        {
            $this->calls++;

            if ($this->calls === 1) {
                throw new RuntimeException('temporary failure');
            }

            return analysisResult();
        }
    };

    expect(fn () => $job->handle($analyzer, app(EntityNormalizer::class)))->toThrow(RuntimeException::class);
    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Processing)
        ->and($article->fresh()->analysis_attempts)->toBe(1);

    $job->handle($analyzer, app(EntityNormalizer::class));

    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($article->fresh()->analysis_attempts)->toBe(2);
});

test('a newer run prevents an older run from completing', function () {
    bindAnalyzer();
    $article = Article::factory()->create();
    $oldJob = new AnalyzeArticleJob($article->id);
    $newJob = new AnalyzeArticleJob($article->id);

    $article->update([
        'analysis_status' => AnalysisStatus::Processing,
        'analysis_run_id' => $newJob->runId,
        'analysis_started_at' => now(),
    ]);

    $oldJob->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));
    $oldJob->failed(new RuntimeException('old run'));

    expect($article->fresh()->analysis_status)->toBe(AnalysisStatus::Processing)
        ->and($article->fresh()->analysis_run_id)->toBe($newJob->runId)
        ->and(Analysis::count())->toBe(0);
});

test('does not persist an old result after a newer run takes the lease', function () {
    $article = Article::factory()->pending()->create();
    $oldJob = new AnalyzeArticleJob($article->id);
    $newJob = new AnalyzeArticleJob($article->id);
    $analyzer = new class($article, $newJob) implements NewsAnalyzer
    {
        public function __construct(
            private readonly Article $article,
            private readonly AnalyzeArticleJob $newJob,
        ) {}

        public function analyze(NewsArticleInput $article): AnalysisResult
        {
            $this->article->update([
                'analysis_status' => AnalysisStatus::Processing,
                'analysis_run_id' => $this->newJob->runId,
                'analysis_started_at' => now(),
            ]);

            return analysisResult();
        }
    };

    $oldJob->handle($analyzer, app(EntityNormalizer::class));

    expect(Analysis::count())->toBe(0)
        ->and(Entity::count())->toBe(0)
        ->and(Tag::count())->toBe(0)
        ->and($article->fresh()->analysis_status)->toBe(AnalysisStatus::Processing)
        ->and($article->fresh()->analysis_run_id)->toBe($newJob->runId);
});

test('does not persist or log throwable secrets', function () {
    Log::spy();
    $article = Article::factory()->pending()->create();
    $secret = 'super-secret-token';
    $job = new AnalyzeArticleJob($article->id);
    $article->update([
        'analysis_status' => AnalysisStatus::Processing,
        'analysis_run_id' => $job->runId,
        'analysis_started_at' => now(),
    ]);

    $job->failed(new RuntimeException($secret));

    expect($article->fresh()->analysis_error)->not->toContain($secret);
    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use ($secret): bool {
        return ! str_contains(json_encode($context), $secret)
            && ! str_contains($message, $secret);
    });
});

test('keeps one-to-one and normalized entity/tag uniqueness constraints', function () {
    $analysisIndexes = collect(Schema::getIndexes('analyses'));
    $entityIndexes = collect(Schema::getIndexes('entities'));
    $tagIndexes = collect(Schema::getIndexes('tags'));

    expect($analysisIndexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['article_id']))->toBeTrue()
        ->and($entityIndexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['type', 'slug']))->toBeTrue()
        ->and($tagIndexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['slug']))->toBeTrue();
});

test('backfills completed execution metadata when the additive migration is applied', function () {
    $analyzedAt = now()->subDay();
    $ineligibleArticle = Article::factory()->create([
        'analysis_status' => AnalysisStatus::Pending,
    ]);
    Analysis::factory()->create([
        'article_id' => $ineligibleArticle->id,
        'analyzed_at' => $analyzedAt,
    ]);
    $article = Article::factory()->analyzed()->create([
        'analysis_status' => AnalysisStatus::Completed,
    ]);
    $article->analysis()->update(['analyzed_at' => $analyzedAt]);
    $migration = require base_path('database/migrations/2026_08_13_030115_add_analysis_execution_metadata_to_articles_table.php');

    $migration->down();
    $migration->up();

    expect($article->fresh()->analysis_attempts)->toBe(1)
        ->and($article->fresh()->analysis_completed_at->toDateTimeString())->toBe($analyzedAt->toDateTimeString());
});

/*
 * Los dos casos de abajo se perdieron en un merge y volvieron a entrar: son
 * regresiones que ningún test cubría, así que nada avisó cuando desaparecieron.
 */

test('does not call the model for an article without text', function () {
    $called = false;
    app()->instance(NewsAnalyzer::class, new class($called) implements NewsAnalyzer
    {
        public function __construct(public bool &$called) {}

        public function analyze(NewsArticleInput $article): AnalysisResult
        {
            $this->called = true;

            return analysisResult();
        }
    });

    $article = Article::factory()->pending()->create(['content' => null, 'excerpt' => null]);

    (new AnalyzeArticleJob($article->id))->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));

    $article->refresh();

    expect($called)->toBeFalse()
        ->and($article->analysis_status)->toBe(AnalysisStatus::Failed)
        ->and($article->analysis_error)->toContain('no tiene texto')
        ->and($article->analysis)->toBeNull();
});

test('analyses an article served over http, without the url as context', function () {
    $receivedUrl = 'sin definir';
    app()->instance(NewsAnalyzer::class, new class($receivedUrl) implements NewsAnalyzer
    {
        public function __construct(public ?string &$receivedUrl) {}

        public function analyze(NewsArticleInput $article): AnalysisResult
        {
            $this->receivedUrl = $article->url;

            return analysisResult();
        }
    });

    // NewsArticleInput solo acepta HTTPS: si la URL viajara tal cual, el DTO
    // lanzaría y el artículo terminaría marcado como fallido sin haberlo
    // intentado nunca.
    $article = Article::factory()->pending()->create(['url' => 'http://df.cl/nota-en-http']);

    (new AnalyzeArticleJob($article->id))->handle(app(NewsAnalyzer::class), app(EntityNormalizer::class));

    expect($receivedUrl)->toBeNull()
        ->and($article->fresh()->analysis_status)->toBe(AnalysisStatus::Completed);
});
