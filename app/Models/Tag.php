<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Etiqueta temática devuelta por el LLM para un artículo.
 */
#[Fillable(['name', 'slug'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public static function firstOrCreateFor(string $name): self
    {
        return static::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name],
        );
    }

    /**
     * @return BelongsToMany<Article, $this>
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }
}
