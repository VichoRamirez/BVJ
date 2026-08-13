<?php

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Medio de origen. Los fallos se acumulan por fuente para poder desactivarla sin
 * afectar al resto del lote.
 */
#[Fillable([
    'name',
    'slug',
    'base_url',
    'spider_class',
    'is_active',
    'last_scraped_at',
    'failure_count',
    'last_failure_reason',
])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_scraped_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
