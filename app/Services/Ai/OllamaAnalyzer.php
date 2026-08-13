<?php

namespace App\Services\Ai;

use App\Contracts\NewsAnalyzer;
use App\Data\AnalysisResult;
use App\Data\NewsArticleInput;
use App\Exceptions\OllamaConfigurationException;
use App\Exceptions\OllamaInvalidResponseException;
use App\Exceptions\OllamaNonRetryableStatusException;
use App\Exceptions\OllamaRetryableStatusException;
use App\Exceptions\OllamaTransportException;
use App\Support\AnalysisJsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class OllamaAnalyzer implements NewsAnalyzer
{
    private const PROVIDER = 'ollama';

    private const SCHEMA_VERSION = '1.0';

    private const MAX_CONNECT_TIMEOUT = 10;

    private const MAX_TIMEOUT = 60;

    private const MAX_BACKOFF = 10_000;

    private const MAX_RESPONSE_BYTES = 10_485_760;

    /**
     * El esquema es compartido con los demás proveedores: si estuviera duplicado
     * acá, agregar una categoría al enum arreglaría un proveedor y dejaría al
     * otro exigiendo un enum viejo.
     *
     * @return array<string, mixed>
     */
    private static function getResponseSchema(): array
    {
        return AnalysisJsonSchema::get();
    }

    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->config = $this->validatedConfig(config('newsscraper.ai.ollama', []));
    }

    public function analyze(NewsArticleInput $article): AnalysisResult
    {
        $model = $this->config['model'];
        $request = $this->request();
        $payload = [
            'model' => $model,
            'stream' => false,
            'options' => ['temperature' => 0],
            'format' => self::getResponseSchema(),
            'messages' => [
                ['role' => 'system', 'content' => 'Analiza datos de noticias y responde exclusivamente con JSON válido según el schema.'],
                ['role' => 'user', 'content' => view('prompts.analyze-article-v1', compact('article'))->render()],
            ],
        ];

        $response = $this->send($request, $payload);
        $body = $this->readBody($response);

        try {
            $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OllamaInvalidResponseException('La respuesta de Ollama no contiene un envelope JSON válido.', previous: $exception);
        }

        $content = is_array($envelope) ? ($envelope['message']['content'] ?? null) : null;

        if (! is_string($content)) {
            throw new OllamaInvalidResponseException('La respuesta de Ollama no contiene contenido textual.');
        }

        return $this->parserFor($model)->parse($content);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->config['base_url'], '/'))
            ->connectTimeout($this->config['connect_timeout'])
            ->timeout($this->config['timeout'])
            ->acceptJson()
            ->withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'on_headers' => function (ResponseInterface $response): void {
                    $contentLength = $response->getHeaderLine('Content-Length');

                    if ($contentLength !== '' && (! ctype_digit($contentLength) || (int) $contentLength > $this->config['max_response_bytes'])) {
                        throw new OllamaInvalidResponseException('La respuesta de Ollama supera el límite permitido.');
                    }
                },
            ]);
    }

    /** @param array<string, mixed> $payload */
    private function send(PendingRequest $request, array $payload): Response
    {
        $attempts = $this->config['retry_attempts'];
        $backoff = $this->config['retry_backoff'];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $request->post('/api/chat', $payload);
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    throw new OllamaTransportException('No fue posible conectar con Ollama.', previous: $exception);
                }

                $this->backoff($backoff, $attempt);

                continue;
            } catch (RequestException $exception) {
                $previous = $exception->getPrevious();

                if ($previous instanceof OllamaInvalidResponseException) {
                    throw $previous;
                }

                throw new OllamaTransportException('Falló la comunicación con Ollama.', previous: $exception);
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $this->closeResponseBody($response);

            if (! in_array($status, [408, 429, 500, 502, 503, 504], true)) {
                throw new OllamaNonRetryableStatusException('Ollama rechazó la solicitud con estado '.$response->status().'.');
            }

            if ($attempt < $attempts) {
                $this->backoff($backoff, $attempt);

                continue;
            }

            throw new OllamaRetryableStatusException('Ollama no pudo procesar la solicitud tras varios intentos.');
        }

        throw new OllamaTransportException('No fue posible completar la solicitud a Ollama.');
    }

    private function closeResponseBody(Response $response): void
    {
        $response->toPsrResponse()->getBody()->close();
    }

    private function readBody(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        $limit = $this->config['max_response_bytes'];

        try {
            while (! $stream->eof() && strlen($body) <= $limit) {
                $remaining = $limit + 1 - strlen($body);

                if ($remaining <= 0) {
                    break;
                }

                $body .= $stream->read(min(8192, $remaining));
            }
        } catch (RuntimeException $exception) {
            throw new OllamaInvalidResponseException('No fue posible leer la respuesta de Ollama.', previous: $exception);
        } finally {
            $stream->close();
        }

        if (strlen($body) > $limit) {
            throw new OllamaInvalidResponseException('La respuesta de Ollama supera el límite permitido.');
        }

        return $body;
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function validatedConfig(array $config): array
    {
        $baseUrl = trim((string) ($config['base_url'] ?? ''));
        $parsedUrl = parse_url($baseUrl);
        $host = trim(strtolower((string) ($parsedUrl['host'] ?? '')), '[]');
        $allowedHosts = ['127.0.0.1', '::1'];

        if ($baseUrl === '' || ($parsedUrl['scheme'] ?? null) !== 'http' || ! in_array($host, $allowedHosts, true) || isset($parsedUrl['user'], $parsedUrl['pass']) || ! isset($parsedUrl['port']) || $parsedUrl['port'] < 1 || $parsedUrl['port'] > 65_535 || isset($parsedUrl['query'], $parsedUrl['fragment'])) {
            throw new OllamaConfigurationException('La URL base de Ollama debe ser HTTP loopback con puerto explícito y sin credenciales.');
        }

        $model = trim((string) ($config['model'] ?? ''));
        $connectTimeout = (float) ($config['connect_timeout'] ?? 0);
        $timeout = (float) ($config['timeout'] ?? 0);
        $attempts = (int) ($config['retry_attempts'] ?? 0);
        $backoff = (int) ($config['retry_backoff'] ?? -1);
        $maxResponseBytes = (int) ($config['max_response_bytes'] ?? 0);

        if ($model === '') {
            throw new OllamaConfigurationException('El modelo de Ollama no puede estar vacío.');
        }

        if ($connectTimeout <= 0 || $connectTimeout > self::MAX_CONNECT_TIMEOUT || $timeout <= 0 || $timeout > self::MAX_TIMEOUT) {
            throw new OllamaConfigurationException('Los timeouts de Ollama deben ser positivos y estar dentro de los límites permitidos.');
        }

        if ($attempts < 1 || $attempts > 5 || $backoff < 0 || $backoff > self::MAX_BACKOFF) {
            throw new OllamaConfigurationException('Los retries de Ollama están fuera de los límites permitidos.');
        }

        if ($maxResponseBytes < 1 || $maxResponseBytes > self::MAX_RESPONSE_BYTES) {
            throw new OllamaConfigurationException('El límite de respuesta de Ollama está fuera de rango.');
        }

        return [
            'base_url' => $baseUrl,
            'model' => $model,
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
            'retry_attempts' => $attempts,
            'retry_backoff' => $backoff,
            'max_response_bytes' => $maxResponseBytes,
        ];
    }

    private function backoff(int $milliseconds, int $attempt): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * $attempt * 1000);
        }
    }

    private function parserFor(string $model): AnalysisResponseParser
    {
        return new AnalysisResponseParser(self::PROVIDER, $model, self::SCHEMA_VERSION);
    }
}
