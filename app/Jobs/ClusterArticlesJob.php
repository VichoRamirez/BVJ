<?php

namespace App\Jobs;

use App\Data\Clustering\ClusterableArticleData;
use App\Data\Clustering\ClusteringOptions;
use App\Enums\AnalysisStatus;
use App\Models\Article;
use App\Models\Event;
use App\Services\Clustering\ArticleClusterer;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClusterArticlesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public int $timeout;

    public function __construct(
        public readonly ?DateTimeInterface $cutoff = null,
        public readonly ?int $windowHours = null,
    ) {
        $this->timeout = max((int) config('newsscraper.clustering.job_timeout', 120), 30);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('article-clustering'))
                ->shared()
                ->releaseAfter($this->timeout)
                ->expireAfter($this->timeout + 31),
        ];
    }

    public function handle(ArticleClusterer $clusterer): void
    {
        $cutoff = $this->cutoff ?? now();
        $options = new ClusteringOptions(
            windowHours: $this->windowHours ?? (int) config('newsscraper.clustering.window_hours', 24),
            tokenJaccardThreshold: (float) config('newsscraper.clustering.title_similarity', 0.62),
            minimumSharedEntities: (int) config('newsscraper.clustering.shared_entities_minimum', 2),
            maxArticles: (int) config('newsscraper.clustering.max_articles', 500),
        );
        $cutoff = Carbon::instance($cutoff);
        $articles = Article::query()
            ->where('analysis_status', AnalysisStatus::Completed->value)
            ->whereNull('event_id')
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$cutoff->copy()->subHours($options->windowHours), $cutoff])
            ->whereHas('analysis')
            ->with(['analysis', 'entities', 'tags'])
            ->orderBy('id')
            ->get();

        Log::info('Article clustering considered', ['considered' => $articles->count()]);

        if ($articles->count() > $options->maxArticles) {
            throw new \RuntimeException('Article count exceeds clustering maxArticles.');
        }

        $byId = $articles->keyBy('id');
        $data = $articles->map(fn (Article $article): ClusterableArticleData => new ClusterableArticleData(
            (string) $article->id,
            $article->title,
            $article->published_at->toImmutable(),
            $article->analysis->category,
            $article->analysis->relevance,
            $article->entities->pluck('name')->all(),
            (string) $article->source_id,
        ))->all();
        $clusters = $clusterer->cluster($data, $options);
        $created = 0;
        $skipped = 0;

        foreach ($clusters as $cluster) {
            $memberIds = array_map(static fn (ClusterableArticleData $member): int => (int) $member->id, $cluster->members);
            $clusterKeyParts = $byId->only($memberIds)
                ->map(fn (Article $article): string => $article->id.':'.$article->url_hash)
                ->sort()
                ->values()
                ->all();
            $clusterKey = hash('sha256', implode(',', $clusterKeyParts));
            try {
                $persisted = DB::transaction(function () use ($cluster, $memberIds, $clusterKey): bool {
                    if (Event::query()->where('cluster_key', $clusterKey)->exists()) {
                        return false;
                    }

                    $members = $this->loadMembersForPersistence($memberIds);

                    $eligible = $members
                        ->where('analysis_status', AnalysisStatus::Completed->value)
                        ->whereNull('event_id')
                        ->whereNotNull('published_at');

                    if ($eligible->count() !== count($memberIds)
                        || $members->pluck('id')->sort()->values()->all() !== collect($memberIds)->sort()->values()->all()
                        || $members->contains(fn (Article $article): bool => $article->analysis === null)
                        || hash('sha256', $members->sortBy('id')->map(fn (Article $article): string => $article->id.':'.$article->url_hash)->implode(',')) !== $clusterKey
                    ) {
                        return false;
                    }

                    $leader = $members->firstWhere('id', (int) $cluster->representativeId);
                    if ($leader === null || $leader->analysis === null) {
                        return false;
                    }
                    $maxRelevance = $members->pluck('analysis.relevance')->sortByDesc(fn ($relevance) => $relevance->weight())->first();
                    $event = Event::query()->create([
                        'cluster_key' => $clusterKey,
                        'slug' => Str::slug($leader->title).'-'.substr($clusterKey, 0, 10),
                        'title' => $leader->title,
                        'summary' => $leader->analysis->summary,
                        'importance' => $leader->analysis->importance_explanation,
                        'category' => $leader->analysis->category,
                        'relevance' => $maxRelevance,
                        'relevance_score' => Event::scoreFor($maxRelevance, $members->pluck('source_id')->unique()->count()),
                        'tags' => $leader->tags->pluck('name')->values()->all(),
                        'first_seen_at' => $members->min('published_at'),
                        'articles_count' => $members->count(),
                    ]);
                    Article::query()->whereKey($memberIds)->update(['event_id' => $event->id]);
                    $this->syncEntities($event, $members);

                    return true;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (! Event::query()->where('cluster_key', $clusterKey)->exists()) {
                    throw $exception;
                }

                $persisted = false;
            }
            $persisted ? $created++ : $skipped++;
        }

        Log::info('Article clustering completed', ['clusters_created' => $created, 'clusters_skipped' => $skipped]);
    }

    /**
     * Reload the locked persistence snapshot so no pre-transaction model data is
     * copied into the event.
     *
     * @param  list<int>  $memberIds
     * @return Collection<int, Article>
     */
    protected function loadMembersForPersistence(array $memberIds): Collection
    {
        return Article::query()
            ->whereKey($memberIds)
            ->with(['analysis', 'entities', 'tags'])
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Article>  $members
     */
    protected function syncEntities(Event $event, Collection $members): void
    {
        $event->entities()->sync($members->flatMap(fn (Article $article) => $article->entities->pluck('id'))->unique()->values()->all());
    }
}
