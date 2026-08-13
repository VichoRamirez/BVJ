<?php

namespace App\Models;

use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Acontecimiento único: agrupa los artículos de distintas fuentes que hablan de
 * lo mismo. Es la unidad que se muestra en el briefing.
 *
 * Lleva denormalizados `summary`, `importance`, `relevance` y `tags` desde el
 * análisis líder del cluster, para que las vistas lean una sola fila. Quien
 * mantiene esa coherencia es `syncAggregatesFromArticles()`.
 *
 * Ojo: `articles_count` es una columna física, no el atributo que genera
 * `withCount()`. Nunca usar `withCount('articles')` sobre este modelo.
 */
#[Fillable([
    'slug',
    'title',
    'summary',
    'importance',
    'category',
    'relevance',
    'relevance_score',
    'tags',
    'first_seen_at',
    'articles_count',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => NewsCategory::class,
            'relevance' => RelevanceLevel::class,
            'relevance_score' => 'integer',
            'articles_count' => 'integer',
            'tags' => 'array',
            'first_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /**
     * @return BelongsToMany<Entity, $this>
     */
    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class)
            ->orderBy('entities.type')
            ->orderBy('entities.name');
    }

    /**
     * @return BelongsToMany<Briefing, $this>
     */
    public function briefings(): BelongsToMany
    {
        return $this->belongsToMany(Briefing::class)->withPivot('position');
    }

    /**
     * Del más relevante al menos relevante.
     *
     * El orden va sobre `relevance_score` (entero) y no sobre `relevance`, que
     * guarda un slug en español y ordenaría alfabéticamente: 'alta' < 'baja' <
     * 'critica' < 'media'.
     *
     * @param  Builder<static>  $query
     */
    public function scopeMostRelevant(Builder $query): void
    {
        $query->orderByDesc('relevance_score')->orderByDesc('first_seen_at');
    }

    /**
     * Puntaje ordenable: la relevancia manda, y aparecer en varios medios
     * desempata (PLAN.md §4.2).
     */
    public static function scoreFor(RelevanceLevel $relevance, int $distinctSources): int
    {
        $bonus = (int) config('newsscraper.relevance.source_bonus', 5);
        $cap = (int) config('newsscraper.relevance.max_source_bonus', 25);

        return $relevance->weight() * 100 + min($distinctSources * $bonus, $cap);
    }

    /**
     * Recalcula lo denormalizado a partir de los artículos del cluster:
     * conteo, puntaje y entidades. Lo usan el seeder, las factories y —desde la
     * semana 3— ClusterArticlesJob.
     */
    public function syncAggregatesFromArticles(): void
    {
        $articles = $this->articles()->with('entities')->get();

        $this->entities()->sync(
            $articles->flatMap(fn (Article $article): Collection => $article->entities->pluck('id'))
                ->unique()
                ->all()
        );

        $this->forceFill([
            'articles_count' => $articles->count(),
            'relevance_score' => static::scoreFor(
                $this->relevance,
                $articles->pluck('source_id')->unique()->count(),
            ),
        ])->save();
    }

    /**
     * Cuántos acontecimientos hay por categoría, para <x-category-nav>.
     *
     * Va por `toBase()` para que las claves salgan como string (el slug de la
     * categoría) en vez de pasar por el cast a enum, que es justo lo que la
     * vista indexa con `$counts[$category->value]`.
     *
     * @return Collection<string, int>
     */
    public static function categoryCounts(): Collection
    {
        return static::query()
            ->toBase()
            ->selectRaw('category, count(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');
    }
}
