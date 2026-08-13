<?php

namespace App\Jobs;

use App\Contracts\NewsAnalyzer;
use App\Data\NewsArticleInput;
use App\Enums\AnalysisStatus;
use App\Enums\EntityType;
use App\Exceptions\AnalysisLeaseLostException;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Entity;
use App\Models\Tag;
use App\Services\Clustering\EntityNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeArticleJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries;

    public int $timeout;

    public string $runId;

    public function __construct(public int $articleId)
    {
        $this->tries = (int) config('newsscraper.ai.job_tries', 4);
        $this->timeout = (int) config('newsscraper.ai.job_timeout', 180);
        $this->runId = (string) Str::uuid();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('article:'.$this->articleId))
                ->shared()
                ->releaseAfter((int) config('newsscraper.ai.overlap_release_after', 30))
                ->expireAfter((int) config('newsscraper.ai.overlap_ttl', 240)),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(NewsAnalyzer $analyzer, EntityNormalizer $normalizer): void
    {
        $article = Article::query()->findOrFail($this->articleId);

        if ($article->analysis_status === AnalysisStatus::Completed) {
            return;
        }

        $staleBefore = now()->subSeconds((int) config('newsscraper.ai.processing_stale_after', 300));
        $canStart = $article->analysis_status !== AnalysisStatus::Processing
            || $article->analysis_started_at === null
            || $article->analysis_started_at->lte($staleBefore)
            || $article->analysis_run_id === $this->runId;

        if (! $canStart) {
            return;
        }

        $updated = Article::query()
            ->whereKey($article->id)
            ->where('analysis_status', '!=', AnalysisStatus::Completed->value)
            ->when($article->analysis_status === AnalysisStatus::Processing, function ($query) use ($staleBefore): void {
                $query->where(function ($query) use ($staleBefore): void {
                    $query->whereNull('analysis_started_at')
                        ->orWhere('analysis_started_at', '<=', $staleBefore)
                        ->orWhere('analysis_run_id', $this->runId);
                });
            })
            ->update([
                'analysis_status' => AnalysisStatus::Processing,
                'analysis_attempts' => $article->analysis_attempts + 1,
                'analysis_error' => null,
                'analysis_started_at' => now(),
                'analysis_completed_at' => null,
                'analysis_run_id' => $this->runId,
            ]);

        if ($updated !== 1) {
            return;
        }

        $input = new NewsArticleInput(
            title: $article->title,
            content: (string) $article->content,
            excerpt: $article->excerpt,
            url: $article->url,
        );
        $result = $analyzer->analyze($input);

        try {
            DB::transaction(function () use ($article, $normalizer, $result): void {
                $leasedArticle = Article::query()
                    ->whereKey($article->id)
                    ->where('analysis_status', AnalysisStatus::Processing->value)
                    ->where('analysis_run_id', $this->runId)
                    ->lockForUpdate()
                    ->first();

                if ($leasedArticle === null) {
                    return;
                }

                Analysis::updateOrCreate(
                    ['article_id' => $article->id],
                    [
                        'provider' => $result->provider,
                        'model' => $result->model,
                        'schema_version' => $result->schemaVersion,
                        'summary' => $result->summary,
                        'category' => $result->category,
                        'relevance' => $result->relevance,
                        'importance_explanation' => $result->importanceExplanation,
                        'raw_response' => $result->rawResponse,
                        'analyzed_at' => now(),
                    ],
                );

                $entityIds = collect([
                    [EntityType::Company, $result->companies],
                    [EntityType::Person, $result->people],
                ])->flatMap(function (array $group) use ($normalizer): array {
                    [$type, $names] = $group;

                    return collect($normalizer->canonicalize($names))
                        ->map(fn (string $name): int => Entity::firstOrCreateFor($type, $name)->id)
                        ->all();
                })->all();

                $tagIds = collect($normalizer->canonicalize($result->tags))
                    ->map(fn (string $name): int => Tag::firstOrCreateFor($name)->id)
                    ->all();

                $article->entities()->sync($entityIds);
                $article->tags()->sync($tagIds);
                $completed = Article::query()
                    ->whereKey($article->id)
                    ->where('analysis_status', AnalysisStatus::Processing->value)
                    ->where('analysis_run_id', $this->runId)
                    ->update([
                        'analysis_run_id' => null,
                        'analysis_status' => AnalysisStatus::Completed,
                        'analysis_error' => null,
                        'analysis_started_at' => null,
                        'analysis_completed_at' => now(),
                    ]);

                if ($completed !== 1) {
                    throw new AnalysisLeaseLostException;
                }
            });
        } catch (AnalysisLeaseLostException) {
            return;
        }
    }

    public function failed(Throwable $exception): void
    {
        $article = Article::query()->find($this->articleId);

        if ($article === null) {
            return;
        }

        $updated = Article::query()
            ->whereKey($article->id)
            ->where('analysis_status', '!=', AnalysisStatus::Completed->value)
            ->where('analysis_run_id', $this->runId)
            ->update([
                'analysis_run_id' => null,
                'analysis_status' => AnalysisStatus::Failed,
                'analysis_error' => 'No fue posible completar el análisis.',
                'analysis_started_at' => null,
                'analysis_completed_at' => null,
            ]);

        if ($updated !== 1) {
            return;
        }

        Log::error('Article analysis exhausted retries', [
            'article_id' => $article->id,
            'attempts' => $article->analysis_attempts,
            'exception' => $exception::class,
        ]);
    }
}
