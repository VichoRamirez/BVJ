<?php

namespace App\Services\Ai;

use App\Contracts\AnalyzerUnavailable;
use App\Contracts\NewsAnalyzer;
use App\Data\AnalysisResult;
use App\Data\AnalyzerCandidate;
use App\Data\NewsArticleInput;
use App\Exceptions\NewsAnalysisException;
use App\Exceptions\NoAnalyzerAvailableException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Prueba los modelos en orden y se queda con el primero que responda.
 *
 * Es un `NewsAnalyzer` más: el pipeline pide el contrato y recibe esto sin
 * enterarse. Cuál modelo terminó respondiendo queda registrado en
 * `analyses.provider` y `analyses.model`, así que siempre se puede auditar.
 *
 * **Solo cambia de modelo ante indisponibilidad** (`AnalyzerUnavailable`):
 * timeout, 429, 503, configuración ausente. Una respuesta mal formada **no**
 * dispara el salto por defecto, porque casi siempre es un problema del prompt o
 * del esquema y taparlo con otro modelo lo esconde justo cuando hay que verlo.
 * Con modelos gratuitos eso pasa seguido, así que se puede activar con
 * `NEWS_AI_FALLBACK_ON_INVALID=true` — a sabiendas de lo que se pierde.
 */
class FallbackNewsAnalyzer implements NewsAnalyzer
{
    /**
     * @param  list<AnalyzerCandidate>  $candidates
     */
    public function __construct(
        private readonly array $candidates,
        private readonly AnalyzerCircuitBreaker $breaker = new AnalyzerCircuitBreaker,
    ) {
        if ($candidates === []) {
            throw new InvalidArgumentException('La cadena de análisis no puede estar vacía.');
        }
    }

    public function analyze(NewsArticleInput $article): AnalysisResult
    {
        $skipped = [];
        $failures = [];

        foreach ($this->candidates as $candidate) {
            if ($this->breaker->isOpen($candidate->label)) {
                $skipped[] = $candidate->label;

                continue;
            }

            try {
                $result = ($candidate->resolve)()->analyze($article);
            } catch (Throwable $exception) {
                if (! $this->shouldFallBack($exception)) {
                    throw $exception;
                }

                $this->breaker->recordFailure($candidate->label);
                $failures[$candidate->label] = $exception::class.': '.$exception->getMessage();

                continue;
            }

            $this->breaker->recordSuccess($candidate->label);

            if ($failures !== [] || $skipped !== []) {
                Log::info('El análisis se resolvió con un modelo de respaldo.', [
                    'usado' => $candidate->label,
                    'fallaron' => array_keys($failures),
                    'omitidos_por_cortocircuito' => $skipped,
                ]);
            }

            return $result;
        }

        Log::error('Ningún modelo de la cadena pudo analizar el artículo.', [
            'fallaron' => $failures,
            'omitidos_por_cortocircuito' => $skipped,
        ]);

        throw new NoAnalyzerAvailableException(
            'Ningún modelo de la cadena de análisis está disponible ('.count($this->candidates).' configurados).'
        );
    }

    /**
     * Un error al construir el analizador (config inválida, API key ausente)
     * cuenta como indisponible: ese eslabón no sirve, pero los demás sí.
     */
    private function shouldFallBack(Throwable $exception): bool
    {
        if ($exception instanceof AnalyzerUnavailable) {
            return true;
        }

        return $exception instanceof NewsAnalysisException
            && (bool) config('newsscraper.ai.fallback.on_invalid_response', false);
    }
}
