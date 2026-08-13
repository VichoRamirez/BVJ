<?php

namespace App\Models;

use App\Enums\EntityType;
use Database\Factories\EntityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Empresa o persona mencionada en un artículo.
 */
#[Fillable(['type', 'name', 'slug'])]
class Entity extends Model
{
    /** @use HasFactory<EntityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EntityType::class,
        ];
    }

    /**
     * Punto único de normalización: "Codelco", "CODELCO" y "codelco" resuelven
     * a la misma fila.
     */
    public static function firstOrCreateFor(EntityType $type, string $name): self
    {
        return static::firstOrCreate(
            ['type' => $type, 'slug' => Str::slug($name)],
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

    /**
     * @return BelongsToMany<Event, $this>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class);
    }
}
