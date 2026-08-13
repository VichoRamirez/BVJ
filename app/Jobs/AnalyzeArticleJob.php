<?php

namespace App\Jobs;

use App\Contracts\NewsAnalyzer;
use App\Data\AnalysisResult;
use App\Data\NewsArticleInput;
use App\Enums\AnalysisStatus;
use App\Enums\EntityType;
use App\Exceptions\ArticleInputTooLargeException;
use App\Exceptions\InvalidArticleUrlException;
use App\Exceptions\NewsAnalysisException;
use App\Exceptions\OllamaRetryableStatusException;
use App\Exceptions\OllamaTransportException;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Entity;
use App\Models\Tag;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manda un artículo al LLM y persiste el análisis validado.
 *
 * El criterio de fallo distingue dos mundos, y la diferencia importa:
 *
 * - **El modelo respondió mal** (JSON inválido, esquema incumplido, texto
 *   demasiado largo): es culpa del contenido, reintentarlo mañana daría lo
 *   mismo. Se reintenta una sola vez en el acto —el modelo no es determinista—
 *   y si vuelve a fallar el artículo queda en `failed`. Nunca se guarda un
 *   análisis a medias (CLAUDE.md §4).
 * - **No se pudo hablar con el modelo** (Ollama caído, timeout, 503): no es
 *   culpa del artículo. Se relanza para que la cola reintente con backoff, y
 *   `ThrottlesExceptions` evita seguir golpeando un servicio caído mientras
 *   tanto.
 */
class AnalyzeArticleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $articleId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300)];
    }

    public function handle(NewsAnalyzer $analyzer): void
    {
        $article = Article::query()->find($this->articleId);

        if ($article === null || $article->analysis_status === AnalysisStatus::Completed) {
            return;
        }

        $content = trim((string) ($article->content ?? $article->excerpt ?? ''));

        if ($content === '') {
            $this->markFailed($article, 'El artículo no tiene texto que analizar.');

            return;
        }

        try {
            $input = new NewsArticleInput(
                title: $article->title,
                content: $content,
                excerpt: $article->excerpt,
                // El DTO solo acepta HTTPS; una fuente en HTTP no debe hacer
                // fallar el análisis, simplemente va sin URL de contexto.
                url: str_starts_with($article->url, 'https://') ? $article->url : null,
            );
        } catch (ArticleInputTooLargeException|InvalidArticleUrlException $exception) {
            $this->markFailed($article, $exception->getMessage());

            return;
        }

        try {
            $result = $this->analyzeWithSingleRetry($analyzer, $input);
        } catch (OllamaTransportException|OllamaRetryableStatusException $exception) {
            throw $exception;
        } catch (NewsAnalysisException $exception) {
            $this->markFailed($article, $exception->getMessage(), $exception);

            return;
        }

        $this->persist($article, $result);
    }

    /**
     * Un reintento inmediato ante una respuesta mal formada: el modelo no es
     * determinista y la segunda pasada suele salir bien.
     */
    private function analyzeWithSingleRetry(NewsAnalyzer $analyzer, NewsArticleInput $input): AnalysisResult
    {
        try {
            return $analyzer->analyze($input);
        } catch (OllamaTransportException|OllamaRetryableStatusException $exception) {
            throw $exception;
        } catch (NewsAnalysisException $exception) {
            Log::warning('El análisis falló y se reintenta una vez.', [
                'article_id' => $this->articleId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $analyzer->analyze($input);
        }
    }

    private function persist(Article $article, AnalysisResult $result): void
    {
        DB::transaction(function () use ($article, $result): void {
            Analysis::updateOrCreate(
                ['article_id' => $article->id],
                [
                    'provider' => $result->provider,
                    'model' => $result->model,
                    'schema_version' => $result->schemaVersion,
                    'summary' => $result->summary,
                    'category' => $result->category,
                    'relevance' => $result->relevance,
                    'importance_explanation' => $result->importanceExplanation,
                    'raw_response' => $result->rawResponse,
                    'analyzed_at' => now(),
                ],
            );

            $entities = [
                ...array_map(
                    fn (string $name): int => Entity::firstOrCreateFor(EntityType::Company, $name)->id,
                    $result->companies,
                ),
                ...array_map(
                    fn (string $name): int => Entity::firstOrCreateFor(EntityType::Person, $name)->id,
                    $result->people,
                ),
            ];

            $article->entities()->sync(array_unique($entities));

            $article->tags()->sync(array_unique(array_map(
                fn (string $name): int => Tag::firstOrCreateFor($name)->id,
                $result->tags,
            )));

            $article->update(['analysis_status' => AnalysisStatus::Completed]);
        });
    }

    private function markFailed(Article $article, string $reason, ?Throwable $exception = null): void
    {
        $article->update(['analysis_status' => AnalysisStatus::Failed]);

        Log::error('El análisis de un artículo quedó marcado como fallido.', [
            'article_id' => $article->id,
            'reason' => $reason,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * Se agotaron los reintentos de la cola (típicamente Ollama caído): el
     * artículo queda visible como fallido en vez de colgado en `pending`.
     */
    public function failed(?Throwable $exception): void
    {
        $article = Article::query()->find($this->articleId);

        if ($article !== null && $article->analysis_status !== AnalysisStatus::Completed) {
            $this->markFailed($article, 'Se agotaron los reintentos del análisis.', $exception);
        }
    }
}
