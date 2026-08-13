<?php

namespace App\Jobs;

use App\Enums\BriefingEdition;
use App\Enums\RelevanceLevel;
use App\Models\Briefing;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publica la edición del período con los acontecimientos más relevantes.
 *
 * El corte del período es "desde el briefing anterior": lo que ya salió en la
 * edición de la mañana no se repite en la de la tarde. Si no hay briefing
 * previo (primera corrida), se toman las últimas doce horas.
 *
 * Una edición vacía no se publica. Es preferible que la portada siga mostrando
 * el briefing anterior a que muestre uno nuevo sin contenido.
 */
class GenerateBriefingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly BriefingEdition $edition) {}

    public function handle(): void
    {
        $scheduled = $this->publicationTime();
        // Se guarda en UTC y se muestra en horario de Chile (CLAUDE.md §4). El
        // cast de Eloquent formatea la fecha sin convertir la zona, así que la
        // conversión tiene que ser explícita: sin esto, las 07:00 de Chile
        // quedarían escritas como 07:00 UTC, o sea las 03:00 acá.
        $publishedAt = $scheduled->utc();
        $events = $this->eventsForPeriod($publishedAt);

        if ($events->isEmpty()) {
            Log::warning('No hay acontecimientos suficientes para publicar la edición.', [
                'edition' => $this->edition->value,
                'published_at' => $publishedAt->toIso8601String(),
            ]);

            return;
        }

        DB::transaction(function () use ($events, $scheduled, $publishedAt): void {
            $briefing = $this->editionOf($scheduled);
            $briefing->fill(['published_at' => $publishedAt])->save();

            $briefing->events()->sync(
                $events->values()
                    ->mapWithKeys(fn (Event $event, int $index): array => [
                        $event->id => ['position' => $index + 1],
                    ])
                    ->all()
            );
        });

        Log::info('Briefing publicado.', [
            'edition' => $this->edition->value,
            'events' => $events->count(),
        ]);
    }

    /**
     * La hora programada de la edición en horario de Chile, no la hora en que
     * corrió el job: si el scheduler se atrasa cinco minutos, la edición de las
     * 07:00 sigue siendo la de las 07:00.
     *
     * Es pública y estática porque `news:pipeline` necesita la misma cuenta para
     * saber si esta corrida publicó algo, y duplicar la fórmula sería garantizar
     * que las dos se desincronicen.
     */
    public static function scheduledAt(BriefingEdition $edition): CarbonImmutable
    {
        return CarbonImmutable::now(config('newsscraper.briefing.timezone'))
            ->setTime($edition->scheduledHour(), 0);
    }

    private function publicationTime(): CarbonImmutable
    {
        return static::scheduledAt($this->edition);
    }

    /**
     * La fila de esta edición, existente o nueva.
     *
     * No se usa `updateOrCreate` porque el cast `date` escribe `published_on`
     * con formato de fecha y hora: buscar por la igualdad "2026-08-12" nunca
     * encuentra el "2026-08-12 00:00:00" que quedó guardado, y el insert choca
     * contra el unique. Por eso la búsqueda va con `whereDate` (mismo motivo que
     * `Briefing::scopeSameDayAs`).
     */
    private function editionOf(CarbonImmutable $scheduled): Briefing
    {
        return Briefing::query()
            ->whereDate('published_on', $scheduled->toDateString())
            ->where('edition', $this->edition)
            ->first()
            ?? new Briefing([
                'published_on' => $scheduled->toDateString(),
                'edition' => $this->edition,
            ]);
    }

    /**
     * @return Collection<int, Event>
     */
    private function eventsForPeriod(CarbonImmutable $publishedAt): Collection
    {
        $previous = Briefing::query()
            ->where('published_at', '<', $publishedAt)
            ->latest('published_at')
            ->first();

        $since = $previous?->published_at ?? $publishedAt->subHours(12);
        $minimumScore = $this->minimumScore();

        return Event::query()
            ->where('first_seen_at', '>=', $since)
            ->where('relevance_score', '>=', $minimumScore)
            ->mostRelevant()
            ->limit((int) config('newsscraper.briefing.events_per_edition', 7))
            ->get();
    }

    /**
     * El umbral vive como nivel legible en config y se traduce acá al entero
     * con que se ordena en SQL (ver Event::scoreFor()).
     */
    private function minimumScore(): int
    {
        $level = RelevanceLevel::tryFrom((string) config('newsscraper.relevance.minimum_for_briefing', 'medium'))
            ?? RelevanceLevel::Medium;

        return $level->weight() * 100;
    }
}
