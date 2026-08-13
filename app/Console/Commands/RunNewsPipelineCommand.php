<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\BriefingEdition;
use App\Jobs\AnalyzeArticleJob;
use App\Jobs\ClusterArticlesJob;
use App\Jobs\FetchMarketSnapshotsJob;
use App\Jobs\GenerateBriefingJob;
use App\Jobs\ScrapeSourceJob;
use App\Models\Article;
use App\Models\Briefing;
use App\Models\Event;
use App\Models\MarketSnapshot;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Orquestador del pipeline completo: recolectar → analizar → agrupar → publicar.
 *
 * Las cuatro etapas corren **en orden y en este proceso**, no repartidas en la
 * cola. Es deliberado: agrupar antes de que terminen los análisis produciría
 * acontecimientos incompletos, y encadenar eso con la cola pediría batches y
 * sincronización que el MVP no necesita. Siguen siendo Jobs —toda llamada
 * externa vive dentro de uno— solo que despachados en forma síncrona desde una
 * consola, nunca desde un request HTTP (CLAUDE.md §4).
 *
 * Lo llama el scheduler a las 07:00 y a las 18:00, y también sirve a mano para
 * la demo.
 */
class RunNewsPipelineCommand extends Command
{
    protected $signature = 'news:pipeline
        {--edition= : morning u evening; por defecto se deduce de la hora}
        {--spider= : Clase de spider a usar en todas las fuentes}
        {--skip-scrape : Saltarse la recolección y analizar lo que ya está en la base}
        {--skip-markets : Saltarse la captura de datos de mercado}';

    protected $description = 'Corre el pipeline completo y publica la edición del período';

    public function handle(): int
    {
        $edition = $this->edition();

        if ($edition === null) {
            $this->components->error('La edición debe ser "morning" o "evening".');

            return self::FAILURE;
        }

        $this->components->info("Pipeline · {$edition->label()}");

        if (! $this->option('skip-scrape')) {
            $this->scrape();
        }

        $this->analyze();
        $this->cluster();

        if (! $this->option('skip-markets')) {
            $this->markets();
        }

        return $this->publish($edition);
    }

    private function edition(): ?BriefingEdition
    {
        if ($option = $this->option('edition')) {
            return BriefingEdition::tryFrom($option);
        }

        $hour = CarbonImmutable::now(config('newsscraper.briefing.timezone'))->hour;

        return $hour < BriefingEdition::Evening->scheduledHour()
            ? BriefingEdition::Morning
            : BriefingEdition::Evening;
    }

    private function scrape(): void
    {
        $sources = Source::query()->where('is_active', true)->orderBy('name')->get();
        $spider = $this->option('spider') ?: null;

        foreach ($sources as $source) {
            // ScrapeSourceJob no relanza: un fallo queda en la fila de la fuente.
            dispatch_sync(new ScrapeSourceJob($source->id, $spider, queueAnalysis: false));
        }

        $this->components->twoColumnDetail('Fuentes recolectadas', (string) $sources->count());
    }

    private function analyze(): void
    {
        $pending = Article::query()
            ->where('analysis_status', AnalysisStatus::Pending)
            ->orderBy('id')
            ->pluck('id');

        $failed = 0;

        foreach ($pending as $articleId) {
            try {
                dispatch_sync(new AnalyzeArticleJob($articleId));
            } catch (Throwable $exception) {
                // Ollama caído o inalcanzable. El artículo sigue en `pending` y
                // la próxima corrida lo reintenta; el lote no se cae.
                $failed++;
                $this->components->warn("Artículo {$articleId}: {$exception->getMessage()}");
            }
        }

        $this->components->twoColumnDetail(
            'Artículos analizados',
            ($pending->count() - $failed).' de '.$pending->count()
        );
    }

    /**
     * Los datos de mercado son apoyo visual del briefing, no su contenido: si
     * Yahoo no responde, la edición se publica igual con las últimas
     * cotizaciones que haya en la base.
     */
    private function markets(): void
    {
        dispatch_sync(new FetchMarketSnapshotsJob);

        $latest = MarketSnapshot::query()->latestPerSymbol()->count();

        $this->components->twoColumnDetail(
            'Instrumentos con cotización',
            $latest.' de '.count(config('newsscraper.markets.instruments', []))
        );
    }

    private function cluster(): void
    {
        dispatch_sync(new ClusterArticlesJob);

        $this->components->twoColumnDetail(
            'Acontecimientos en la ventana',
            (string) Event::query()->count()
        );
    }

    /**
     * La fecha editorial: el día en Chile al que pertenece esta corrida.
     *
     * GenerateBriefingJob la exige explícita en vez de deducirla de `now()`, y
     * está bien que así sea: permite reconstruir a mano la edición de ayer si el
     * scheduler no corrió, sin depender de cuándo se ejecute el comando.
     */
    private function editorialDate(): string
    {
        return CarbonImmutable::now(config('newsscraper.briefing.timezone'))->toDateString();
    }

    private function publish(BriefingEdition $edition): int
    {
        $editorialDate = $this->editorialDate();
        $existedBefore = $this->editionOf($edition, $editorialDate) !== null;

        dispatch_sync(new GenerateBriefingJob($edition, $editorialDate));

        // Se busca la edición exacta (fecha editorial + edición), no "la última
        // de hoy": si el job no publicó nada, cualquier otra fila del día haría
        // que el comando reportara un éxito que no ocurrió.
        $briefing = $this->editionOf($edition, $editorialDate);

        if ($briefing === null) {
            $this->components->warn('No había acontecimientos suficientes: no se publicó edición.');

            return self::FAILURE;
        }

        // El job es idempotente por omisión: si la edición ya existía, no la
        // toca. Se distingue de una publicación nueva para que una corrida
        // repetida no parezca que volvió a generar el briefing.
        $this->components->twoColumnDetail(
            $existedBefore ? 'Edición ya publicada' : 'Edición publicada',
            $briefing->published_at->timezone(config('newsscraper.briefing.timezone'))->format('d-m-Y H:i')
        );

        return self::SUCCESS;
    }

    private function editionOf(BriefingEdition $edition, string $editorialDate): ?Briefing
    {
        return Briefing::query()
            ->where('edition', $edition)
            ->whereDate('published_on', $editorialDate)
            ->first();
    }
}
