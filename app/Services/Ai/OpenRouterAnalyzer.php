<?php

namespace App\Services\Ai;

use App\Contracts\NewsAnalyzer;
use App\Data\AnalysisResult;
use App\Data\NewsArticleInput;
use App\Exceptions\OpenRouterConfigurationException;
use App\Exceptions\OpenRouterInvalidResponseException;
use App\Exceptions\OpenRouterNonRetryableStatusException;
use App\Exceptions\OpenRouterRetryableStatusException;
use App\Exceptions\OpenRouterTransportException;
use App\Support\AnalysisJsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Análisis vía OpenRouter, que expone una API compatible con OpenAI y da acceso
 * a modelos gratuitos.
 *
 * A diferencia de `OllamaAnalyzer`, el modelo se pasa por constructor y no sale
 * de la config: eso es lo que permite armar una cadena con varios modelos del
 * mismo proveedor, que es el caso común —los modelos gratuitos tienen cuotas
 * bajas y el 429 es lo primero que aparece.
 *
 * **La API key nunca se escribe en un log ni en un mensaje de excepción.** Los
 * errores mencionan el modelo y el estado HTTP, nada más.
 */
class OpenRouterAnalyzer implements NewsAnalyzer
{
    private const PROVIDER = 'openrouter';

    private const SCHEMA_VERSION = '1.0';

    /** @var array<string, mixed> */
    private array $config;

    private string $model;

    public function __construct(?string $model = null)
    {
        $this->config = $this->validatedConfig(config('newsscraper.ai.openrouter', []));
        $this->model = trim($model ?? '') !== '' ? trim($model) : $this->config['model'];
    }

    public function analyze(NewsArticleInput $article): AnalysisResult
    {
        $response = $this->send([
            'model' => $this->model,
            'temperature' => 0,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'analisis_noticia',
                    'strict' => true,
                    'schema' => AnalysisJsonSchema::get(),
                ],
            ],
            'messages' => [
                ['role' => 'system', 'content' => 'Analiza datos de noticias y responde exclusivamente con JSON válido según el schema.'],
                ['role' => 'user', 'content' => view('prompts.analyze-article-v1', compact('article'))->render()],
            ],
        ]);

        return new AnalysisResponseParser(self::PROVIDER, $this->model, self::SCHEMA_VERSION)
            ->parse($this->contentOf($response));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): Response
    {
        try {
            $response = Http::baseUrl($this->config['base_url'])
                ->withToken($this->config['api_key'])
                ->withHeaders(array_filter([
                    // OpenRouter los usa para atribuir el tráfico; son opcionales.
                    'HTTP-Referer' => $this->config['referer'],
                    'X-Title' => $this->config['title'],
                ]))
                ->connectTimeout($this->config['connect_timeout'])
                ->timeout($this->config['timeout'])
                ->retry($this->config['retry_attempts'], $this->config['retry_backoff'], throw: false)
                ->acceptJson()
                ->post('/chat/completions', $payload);
        } catch (ConnectionException $exception) {
            throw new OpenRouterTransportException("No fue posible conectar con OpenRouter ({$this->model}).", previous: $exception);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        // 429 es lo habitual con modelos gratuitos: la cadena pasa al siguiente.
        if (in_array($status, [408, 429, 500, 502, 503, 504], true)) {
            throw new OpenRouterRetryableStatusException("OpenRouter respondió {$status} para {$this->model}.");
        }

        throw new OpenRouterNonRetryableStatusException("OpenRouter rechazó la solicitud con estado {$status} para {$this->model}.");
    }

    private function contentOf(Response $response): string
    {
        $body = $response->body();

        if (strlen($body) > $this->config['max_response_bytes']) {
            throw new OpenRouterInvalidResponseException('La respuesta de OpenRouter supera el límite permitido.');
        }

        try {
            $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OpenRouterInvalidResponseException('La respuesta de OpenRouter no es JSON válido.', previous: $exception);
        }

        $content = is_array($envelope) ? ($envelope['choices'][0]['message']['content'] ?? null) : null;

        if (! is_string($content) || trim($content) === '') {
            throw new OpenRouterInvalidResponseException("OpenRouter no devolvió contenido para {$this->model}.");
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function validatedConfig(array $config): array
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        $model = trim((string) ($config['model'] ?? ''));

        if ($apiKey === '') {
            throw new OpenRouterConfigurationException('Falta OPENROUTER_API_KEY.');
        }

        $parsedUrl = parse_url($baseUrl);

        if (($parsedUrl['scheme'] ?? null) !== 'https' || empty($parsedUrl['host'])) {
            throw new OpenRouterConfigurationException('La URL base de OpenRouter debe ser HTTPS.');
        }

        if ($model === '') {
            throw new OpenRouterConfigurationException('El modelo por defecto de OpenRouter no puede estar vacío.');
        }

        return [
            'api_key' => $apiKey,
            'base_url' => $baseUrl,
            'model' => $model,
            'referer' => trim((string) ($config['referer'] ?? '')) ?: null,
            'title' => trim((string) ($config['title'] ?? '')) ?: null,
            'connect_timeout' => max(1.0, (float) ($config['connect_timeout'] ?? 5)),
            'timeout' => max(1.0, (float) ($config['timeout'] ?? 60)),
            'retry_attempts' => max(1, (int) ($config['retry_attempts'] ?? 2)),
            'retry_backoff' => max(0, (int) ($config['retry_backoff'] ?? 500)),
            'max_response_bytes' => max(1, (int) ($config['max_response_bytes'] ?? 1_048_576)),
        ];
    }
}
