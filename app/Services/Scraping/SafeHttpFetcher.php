<?php

namespace App\Services\Scraping;

use App\Exceptions\DisallowedScrapingTargetException;
use App\Exceptions\SourceScrapingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Única puerta de salida a internet del scraping.
 *
 * Ningún spider hace requests por su cuenta: todos pasan por acá, y por eso
 * todas las reglas viven en un solo lugar en vez de repetirse (y olvidarse) en
 * cada fuente nueva.
 *
 * Lo que garantiza, en orden:
 *
 * 1. **Allowlist de hosts.** Solo se sale a los dominios de
 *    `config('newsscraper.scraping.allowed_hosts')`.
 * 2. **SSRF.** El host se resuelve y se rechaza si apunta a una dirección
 *    privada o reservada, para que un DNS comprometido no convierta al bot en
 *    un cliente de la red interna.
 * 3. **Redirecciones controladas.** No las sigue el cliente HTTP: se siguen a
 *    mano, revalidando allowlist, SSRF y robots en **cada** salto. Una fuente
 *    no puede empujarnos a otro sitio.
 * 4. **robots.txt** y **retardo entre requests** al mismo host.
 * 5. **Tamaño máximo** y normalización de codificación a UTF-8.
 */
final class SafeHttpFetcher
{
    /** @var array<string, float> Marca de tiempo del último request por host. */
    private array $lastRequestAt = [];

    public function __construct(private readonly RobotsTxtGate $robots = new RobotsTxtGate) {}

    public function get(string $url): string
    {
        $config = config('newsscraper.scraping');
        $maxRedirects = (int) ($config['max_redirects'] ?? 3);

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $this->assertAllowed($url);
            $this->delayFor($url);

            try {
                $response = Http::withHeaders(['User-Agent' => $config['user_agent']])
                    ->timeout((int) ($config['request_timeout'] ?? 20))
                    ->retry((int) ($config['retry_attempts'] ?? 2), (int) ($config['retry_backoff'] ?? 500), throw: false)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url);
            } catch (ConnectionException $exception) {
                throw new SourceScrapingException("No fue posible conectar con {$url}.", previous: $exception);
            }

            if ($response->redirect()) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new SourceScrapingException("Redirección sin destino desde {$url}.");
                }

                $url = $this->resolveLocation($url, $location);

                continue;
            }

            if ($response->failed()) {
                throw new SourceScrapingException("{$url} respondió {$response->status()}.");
            }

            return $this->readBody($response->body(), (int) ($config['max_response_bytes'] ?? 5_242_880));
        }

        throw new SourceScrapingException("Demasiadas redirecciones al pedir {$url}.");
    }

    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? null) !== 'https' || $host === '') {
            throw new DisallowedScrapingTargetException("Solo se permiten URLs HTTPS absolutas: {$url}.");
        }

        $allowed = array_map('strtolower', (array) config('newsscraper.scraping.allowed_hosts', []));

        if (! in_array($host, $allowed, true)) {
            throw new DisallowedScrapingTargetException("El host {$host} no está en la allowlist de scraping.");
        }

        $this->assertPublicHost($host);

        if (! $this->robots->allows($url)) {
            throw new DisallowedScrapingTargetException("robots.txt prohíbe {$url}.");
        }
    }

    /**
     * Se puede apagar en entornos donde no hay DNS (los tests); la lógica de
     * rangos vive aparte, en `isPublicAddress()`, y esa sí se prueba siempre.
     */
    private function assertPublicHost(string $host): void
    {
        if (! config('newsscraper.scraping.verify_public_address', true)) {
            return;
        }

        $addresses = gethostbynamel($host) ?: [];

        if ($addresses === [] || ! $this->isPublicAddress($addresses)) {
            throw new DisallowedScrapingTargetException("El host {$host} no resuelve a una dirección pública.");
        }
    }

    /**
     * @param  list<string>  $addresses
     */
    public function isPublicAddress(array $addresses): bool
    {
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            // Basta que una sola resuelva a red privada para descartar el host:
            // el cliente HTTP podría elegir justamente esa.
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    private function resolveLocation(string $from, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($from);
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return $base.'/'.ltrim($location, '/');
    }

    /**
     * Retardo entre requests al mismo host. Se mide contra el request anterior,
     * no se duerme a ciegas: si el análisis del feed ya tomó tres segundos, no
     * hay que esperar dos más.
     */
    private function delayFor(string $url): void
    {
        $host = (string) (parse_url($url)['host'] ?? '');
        $delay = (int) config('newsscraper.scraping.delay_seconds', 2);

        if ($delay > 0 && isset($this->lastRequestAt[$host])) {
            $elapsed = microtime(true) - $this->lastRequestAt[$host];

            if ($elapsed < $delay) {
                usleep((int) (($delay - $elapsed) * 1_000_000));
            }
        }

        $this->lastRequestAt[$host] = microtime(true);
    }

    private function readBody(string $body, int $maxBytes): string
    {
        if (strlen($body) > $maxBytes) {
            throw new SourceScrapingException('La respuesta supera el tamaño máximo permitido.');
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            $detected = mb_detect_encoding($body, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'ISO-8859-1';
            $body = mb_convert_encoding($body, 'UTF-8', $detected);
        }

        return $body;
    }
}
