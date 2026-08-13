<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Support\CanonicalUrl;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Publicación individual scrapeada de una Source.
 *
 * `url_hash` queda deliberadamente fuera del fillable: es un valor derivado que
 * calcula el hook `saving()`, de modo que sea imposible guardar una fila con el
 * hash desalineado de la URL, venga de una factory, de un seeder o de un job.
 */
#[Fillable([
    'source_id',
    'event_id',
    'url',
    'title',
    'author',
    'published_at',
    'excerpt',
    'content',
    'scraped_at',
    'analysis_status',
    'analysis_attempts',
    'analysis_error',
    'analysis_started_at',
    'analysis_completed_at',
    'analysis_run_id',
])]
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Article $article): void {
            $article->url_hash = CanonicalUrl::hash((string) $article->url);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'scraped_at' => 'datetime',
            'analysis_status' => AnalysisStatus::class,
            'analysis_started_at' => 'datetime',
            'analysis_completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasOne<Analysis, $this>
     */
    public function analysis(): HasOne
    {
        return $this->hasOne(Analysis::class);
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
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('tags.name');
    }
}
