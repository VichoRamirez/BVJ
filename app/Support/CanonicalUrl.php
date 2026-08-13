<?php

namespace App\Support;

use App\Exceptions\InvalidArticleUrlException;

/**
 * Normalización de URLs de artículos.
 *
 * El hash de la URL normalizada es la clave de idempotencia del pipeline: dos
 * enlaces al mismo artículo que solo difieren en parámetros de campaña, en la
 * barra final o en mayúsculas del host colapsan en la misma fila.
 *
 * Vive en Support y no en el modelo porque `Article::updateOrCreate()` necesita
 * el hash antes de que exista el modelo, y porque lo consumen también los jobs
 * de scraping y los tests.
 */
final class CanonicalUrl
{
    /**
     * Parámetros de seguimiento que no identifican al artículo.
     *
     * @var list<string>
     */
    private const TRACKING_PARAMETERS = [
        'fbclid',
        'gclid',
        'igshid',
        'mc_cid',
        'mc_eid',
        'msclkid',
        'ref',
        'source',
    ];

    public static function hash(string $url): string
    {
        return hash('sha256', self::normalize($url));
    }

    public static function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            throw new InvalidArticleUrlException('La URL del artículo debe usar HTTP o HTTPS, tener un host válido y no incluir credenciales.');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = preg_replace('/^www\./', '', strtolower($parts['host']));

        $normalized = $scheme.'://'.$host;

        if (isset($parts['port']) && ! self::isDefaultPort($scheme, $parts['port'])) {
            $normalized .= ':'.$parts['port'];
        }

        $path = rtrim($parts['path'] ?? '', '/');
        $normalized .= $path === '' ? '/' : $path;

        if ($query = self::normalizeQuery($parts['query'] ?? '')) {
            $normalized .= '?'.$query;
        }

        return $normalized;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);
    }

    /**
     * Descarta parámetros de campaña y ordena el resto, para que el orden en que
     * vienen escritos no cambie el hash.
     */
    private static function normalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $parameters);

        $parameters = array_filter(
            $parameters,
            fn (string $key): bool => ! str_starts_with($key, 'utm_')
                && ! in_array($key, self::TRACKING_PARAMETERS, true),
            ARRAY_FILTER_USE_KEY
        );

        if ($parameters === []) {
            return '';
        }

        ksort($parameters);

        return http_build_query($parameters);
    }
}
