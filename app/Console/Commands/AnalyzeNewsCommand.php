<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Jobs\AnalyzeArticleJob;
use App\Models\Article;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Análisis manual, y la única forma de reintentar lo que falló.
 *
 * `news:pipeline` solo analiza los artículos en `pending`, y `ScrapeSourceJob`
 * tampoco reencola: al re-scrapear, un artículo que ya existe y no está
 * `pending` se salta para no volver a gastar llamadas a la API. La consecuencia
 * es que un `failed` —una respuesta que no cumplió el esquema, un timeout que
 * agotó los reintentos— quedaba muerto en la base sin manera de recuperarlo.
 * Eso es lo que resuelve `--retry-failed`.
 *
 * `--limit` está para acotar el gasto: los modelos gratuitos tienen cuota baja y
 * conviene poder analizar de a poco.
 */
class AnalyzeNewsCommand extends Command
{
    protected $signature = 'news:analyze
        {--source= : Slug de una fuente concreta; por defecto, todas}
        {--retry-failed : Incluir los artículos cuyo análisis falló}
        {--only-failed : Analizar únicamente los que fallaron}
        {--limit= : Máximo de artículos a analizar en esta corrida}';

    protected $description = 'Analiza los artículos pendientes y reintenta los fallidos';

    public function handle(): int
    {
        $source = $this->resolveSource();

        if ($source === false) {
            return self::FAILURE;
        }

        $articles = $this->articles($source);

        if ($articles->isEmpty()) {
            $this->components->info('No hay artículos que analizar.');

            return self::SUCCESS;
        }

        // Un `failed` no lo toma AnalyzeArticleJob: vuelve a `pending` para que
        // el job lo trate como cualquier artículo nuevo.
        Article::query()
            ->whereIn('id', $articles->pluck('id'))
            ->where('analysis_status', AnalysisStatus::Failed)
            ->update(['analysis_status' => AnalysisStatus::Pending, 'analysis_error' => null]);

        $analyzed = 0;
        $failed = 0;

        foreach ($articles as $article) {
            try {
                dispatch_sync(new AnalyzeArticleJob($article->id));
                $analyzed++;
            } catch (Throwable $exception) {
                // Un artículo no puede tumbar el lote: el proveedor puede estar
                // caído o haber agotado la cuota gratuita.
                $failed++;
                $this->components->warn("Artículo {$article->id}: {$exception->getMessage()}");
            }
        }

        $this->components->twoColumnDetail('Artículos procesados', (string) $analyzed);

        if ($failed > 0) {
            $this->components->twoColumnDetail('Con error de transporte', "<fg=yellow>{$failed}</>");
        }

        return self::SUCCESS;
    }

    /**
     * @return Source|null|false `false` si el slug pedido no existe.
     */
    private function resolveSource(): Source|null|false
    {
        $slug = $this->option('source');

        if ($slug === null) {
            return null;
        }

        $source = Source::query()->where('slug', $slug)->first();

        if ($source === null) {
            $this->components->error("No existe la fuente «{$slug}».");

            return false;
        }

        return $source;
    }

    /**
     * @return Collection<int, Article>
     */
    private function articles(?Source $source): Collection
    {
        $statuses = match (true) {
            (bool) $this->option('only-failed') => [AnalysisStatus::Failed],
            (bool) $this->option('retry-failed') => [AnalysisStatus::Pending, AnalysisStatus::Failed],
            default => [AnalysisStatus::Pending],
        };

        return Article::query()
            ->whereIn('analysis_status', array_map(fn (AnalysisStatus $status): string => $status->value, $statuses))
            ->when($source !== null, fn ($query) => $query->where('source_id', $source->id))
            ->orderBy('id')
            ->when($this->option('limit') !== null, fn ($query) => $query->limit(max(1, (int) $this->option('limit'))))
            ->get();
    }
}
