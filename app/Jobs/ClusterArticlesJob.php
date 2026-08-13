<?php

namespace App\Jobs;

use App\Data\Clustering\ArticleCluster;
use App\Data\Clustering\ClusterableArticleData;
use App\Data\Clustering\ClusteringOptions;
use App\Enums\AnalysisStatus;
use App\Models\Article;
use App\Models\Event;
use App\Services\Clustering\ArticleClusterer;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Agrupa en acontecimientos los artículos analizados de la ventana móvil.
 *
 * El algoritmo ya existe y está probado sin base de datos (ArticleClusterer);
 * este job es solo el puente: lee filas, las convierte al DTO del motor,
 * y persiste los grupos que salen.
 *
 * La identidad de un acontecimiento son **sus artículos**, no su título: si
 * alguno de los miembros del grupo ya pertenece a un Event, se reutiliza ese. De
 * ahí salen las dos propiedades que importan:
 *
 * - Es idempotente. Volver a correrlo sobre los mismos artículos actualiza el
 *   mismo Event en vez de abrir otro, y si un medio publica su versión de la
 *   historia dos horas después, la corrida siguiente la suma al acontecimiento
 *   existente aunque el título representativo cambie.
 * - El slug se fija al crear y no se vuelve a tocar, así un enlace compartido no
 *   se rompe porque entró un artículo nuevo al grupo.
 */
class ClusterArticlesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly ?int $windowHours = null) {}

    public function handle(ArticleClusterer $clusterer): void
    {
        $options = $this->options();
        $articles = $this->clusterableArticles($options);

        if ($articles->isEmpty()) {
            Log::info('No hay artículos analizados en la ventana para agrupar.');

            return;
        }

        $clusters = $clusterer->cluster($articles->map($this->toClusterableData(...))->all(), $options);

        foreach ($clusters as $cluster) {
            $this->persist($cluster, $articles);
        }

        Log::info('Agrupación terminada.', [
            'articles' => $articles->count(),
            'events' => count($clusters),
        ]);
    }

    private function options(): ClusteringOptions
    {
        return new ClusteringOptions(
            windowHours: $this->windowHours ?? (int) config('newsscraper.clustering.window_hours', 24),
            tokenJaccardThreshold: (float) config('newsscraper.clustering.title_similarity', 0.62),
            minimumSharedEntities: (int) config('newsscraper.clustering.shared_entities_minimum', 2),
        );
    }

    /**
     * @return Collection<int, Article>
     */
    private function clusterableArticles(ClusteringOptions $options): Collection
    {
        return Article::query()
            ->with(['analysis', 'entities', 'tags', 'source'])
            ->where('analysis_status', AnalysisStatus::Completed)
            ->whereHas('analysis')
            ->where('published_at', '>=', CarbonImmutable::now()->subHours($options->windowHours))
            ->orderByDesc('published_at')
            // El motor rechaza lotes mayores que `maxArticles`; se recorta acá,
            // dejando los más recientes, en vez de dejar que explote.
            ->limit($options->maxArticles)
            ->get();
    }

    private function toClusterableData(Article $article): ClusterableArticleData
    {
        return new ClusterableArticleData(
            id: (string) $article->id,
            title: $article->title,
            publishedAt: new DateTimeImmutable((string) $article->published_at),
            category: $article->analysis->category,
            relevance: $article->analysis->relevance,
            entities: $article->entities->pluck('name')->values()->all(),
            sourceKey: $article->source->slug,
        );
    }

    /**
     * @param  Collection<int, Article>  $articles
     */
    private function persist(ArticleCluster $cluster, Collection $articles): void
    {
        $memberIds = array_map(static fn (ClusterableArticleData $member): int => (int) $member->id, $cluster->members);
        $members = $articles->whereIn('id', $memberIds);

        // El representativo manda el texto del acontecimiento: es el artículo
        // más relevante del grupo, no el primero que llegó.
        $representative = $members->firstWhere('title', $cluster->representativeTitle) ?? $members->first();

        $firstSeenAt = $members->min('published_at');

        DB::transaction(function () use ($cluster, $members, $memberIds, $representative, $firstSeenAt): void {
            $existing = $this->existingEventFor($memberIds);

            $attributes = [
                'title' => $cluster->representativeTitle,
                'summary' => $representative->analysis->summary,
                'importance' => $representative->analysis->importance_explanation,
                'category' => $cluster->category,
                'relevance' => $cluster->maxRelevance,
                'relevance_score' => Event::scoreFor($cluster->maxRelevance, $cluster->distinctSourceCount),
                'tags' => $this->tagsFor($members),
                'articles_count' => count($memberIds),
            ];

            $event = $existing === null
                ? Event::create([
                    ...$attributes,
                    'slug' => $this->availableSlugFor($cluster->representativeTitle),
                    'first_seen_at' => $firstSeenAt,
                ])
                : tap($existing)->update([
                    ...$attributes,
                    // Un acontecimiento no rejuvenece: conserva la primera vez
                    // que se vio, aunque el artículo que llegó después sea el
                    // que ahora lo representa.
                    'first_seen_at' => min($existing->first_seen_at, $firstSeenAt),
                ]);

            Article::query()->whereIn('id', $memberIds)->update(['event_id' => $event->id]);

            $event->syncAggregatesFromArticles();
        });
    }

    /**
     * Etiquetas del acontecimiento: proyección de presentación, no el dato
     * analítico. Las de verdad viven normalizadas en `tags` + `article_tag`.
     *
     * @param  Collection<int, Article>  $members
     * @return list<string>
     */
    private function tagsFor(Collection $members): array
    {
        return $members
            ->flatMap(fn (Article $article): \Illuminate\Support\Collection => $article->tags->pluck('name'))
            ->unique()
            ->sort()
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * El acontecimiento al que ya pertenece alguno de los miembros del grupo.
     *
     * @param  list<int>  $memberIds
     */
    private function existingEventFor(array $memberIds): ?Event
    {
        $eventId = Article::query()
            ->whereIn('id', $memberIds)
            ->whereNotNull('event_id')
            ->orderBy('event_id')
            ->value('event_id');

        return $eventId === null ? null : Event::query()->find($eventId);
    }

    /**
     * Dos acontecimientos distintos pueden tener el mismo titular —pasa cuando
     * dos medios titulan igual dos hechos de categorías distintas—, y el slug es
     * único en la base. El sufijo numérico los separa sin perder legibilidad.
     */
    private function availableSlugFor(string $title): string
    {
        $base = Str::limit(Str::slug($title), 72, '') ?: 'acontecimiento-'.Str::substr(md5($title), 0, 8);
        $slug = $base;
        $suffix = 1;

        while (Event::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }
}
