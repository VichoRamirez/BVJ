<?php

namespace App\Models;

use App\Enums\BriefingEdition;
use Database\Factories\BriefingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Edición publicada (mañana o tarde) con los acontecimientos más relevantes del
 * período.
 */
#[Fillable(['edition', 'published_on', 'published_at'])]
class Briefing extends Model
{
    /** @use HasFactory<BriefingFactory> */
    use HasFactory;

    /**
     * Todo lo que necesitan home.blade.php y briefings/show.blade.php para
     * renderizar sin caer en N+1.
     *
     * @var list<string>
     */
    public const DISPLAY_RELATIONS = ['events.articles.source', 'events.entities'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edition' => BriefingEdition::class,
            'published_on' => 'date',
            'published_at' => 'datetime',
        ];
    }

    /**
     * El orden va en la definición de la relación para que cualquier eager load
     * salga ya ordenado desde SQL: `$briefing->events->first()` es siempre el
     * titular de la edición.
     *
     * @return BelongsToMany<Event, $this>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * Las otras ediciones del mismo día, para la barra AM/PM.
     *
     * Va con `whereDate()` y no con una igualdad: el cast `date` escribe el
     * valor con el formato de fecha y hora del modelo, así que en SQLite la
     * columna guarda "2026-08-10 00:00:00" y comparar contra "2026-08-10" no
     * matchea nunca.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSameDayAs(Builder $query, self $briefing): void
    {
        $query->whereDate('published_on', $briefing->published_on)
            ->orderBy('published_at');
    }
}
