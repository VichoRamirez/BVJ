<?php

namespace App\Jobs;

use App\Data\ScrapedArticle;
use App\Enums\AnalysisStatus;
use App\Models\Article;
use App\Models\Source;
use App\Services\Scraping\SourceScraperResolver;
use App\Support\CanonicalUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recolecta una fuente y persiste sus artículos.
 *
 * Dos garantías que no son negociables:
 *
 * 1. **Aislamiento.** Un fallo de esta fuente se registra en la propia fila de
 *    `sources` y el job termina bien. Nunca se relanza la excepción: si lo
 *    hiciera, el reintento de la cola volvería a golpear una fuente que ya
 *    sabemos caída, y con `--kill-others` en el dev script tumbaría el lote.
 * 2. **Idempotencia.** Los artículos se persisten por `url_hash`, así que
 *    reprocesar la misma corrida actualiza filas en vez de duplicarlas. El
 *    estado del análisis no se pisa nunca en una actualización: un artículo ya
 *    analizado no vuelve a la cola del LLM porque la fuente lo re-publicó.
 */
class ScrapeSourceJob implements ShouldQueue
{
    use Queueable;

    /**
     * Un solo intento: los reintentos por fallo de fuente los decide la próxima
     * corrida del scheduler, no la cola.
     */
    public int $tries = 1;

    /**
     * @param  bool  $queueAnalysis  `news:pipeline` lo pone en false: analiza en su
     *                               propia etapa, en orden, y encolar además sería
     *                               analizar dos veces lo mismo.
     */
    public function __construct(
        public readonly int $sourceId,
        public readonly ?string $spiderClass = null,
        public readonly bool $queueAnalysis = true,
    ) {}

    public function handle(SourceScraperResolver $resolver): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        if (! $source->is_active) {
            Log::info('Fuente inactiva omitida en la recolección.', ['source' => $source->slug]);

            return;
        }

        try {
            $scraped = $resolver->resolve($source, $this->spiderClass)->scrape(
                $source,
                (int) config('newsscraper.scraping.max_articles_per_source', 25),
            );
        } catch (Throwable $exception) {
            $this->recordFailure($source, $exception);

            return;
        }

        $new = 0;

        foreach ($scraped as $article) {
            if ($this->persist($source, $article)) {
                $new++;
            }
        }

        $source->forceFill([
            'last_scraped_at' => now(),
            'failure_count' => 0,
            'last_failure_reason' => null,
        ])->save();

        Log::info('Recolección terminada.', [
            'source' => $source->slug,
            'scraped' => count($scraped),
            'new' => $new,
        ]);
    }

    /**
     * @return bool Si el artículo quedó encolado para análisis.
     */
    private function persist(Source $source, ScrapedArticle $scraped): bool
    {
        $hash = CanonicalUrl::hash($scraped->url);
        $existing = Article::query()->where('url_hash', $hash)->first();

        $article = Article::updateOrCreate(
            ['url_hash' => $hash],
            [
                'source_id' => $source->id,
                'url' => $scraped->url,
                'title' => $scraped->title,
                'author' => $scraped->author,
                // Varios listados no publican la fecha de cada nota. Sin
                // `published_at` el artículo nunca entraría a la ventana del
                // clustering, así que se usa la hora de recolección como
                // aproximación: el listado muestra lo recién publicado.
                'published_at' => $scraped->publishedAt ?? now(),
                'excerpt' => $scraped->excerpt,
                'content' => $scraped->content,
                'scraped_at' => now(),
            ],
        );

        // Solo entra a la cola del LLM lo que todavía no tiene análisis válido.
        // Un `failed` se reintenta a mano con `news:analyze`, no en cada corrida.
        if ($existing !== null && $existing->analysis_status !== AnalysisStatus::Pending) {
            return false;
        }

        $article->update(['analysis_status' => AnalysisStatus::Pending]);

        if ($this->queueAnalysis) {
            AnalyzeArticleJob::dispatch($article->id);
        }

        return true;
    }

    private function recordFailure(Source $source, Throwable $exception): void
    {
        $source->forceFill([
            'failure_count' => $source->failure_count + 1,
            'last_failure_reason' => str($exception->getMessage())->limit(500)->value(),
        ])->save();

        Log::warning('Falló la recolección de una fuente.', [
            'source' => $source->slug,
            'failure_count' => $source->failure_count,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
