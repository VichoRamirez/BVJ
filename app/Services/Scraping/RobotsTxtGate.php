<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Respeto de `robots.txt`. Es requisito del proyecto, no una cortesía
 * (CLAUDE.md §4).
 *
 * El archivo se cachea por host para no pedirlo en cada artículo. Si no se
 * puede leer, se **permite**: un 404 de robots.txt significa que el sitio no
 * declara restricciones, y un sitio caído ya va a fallar en la request real.
 * Lo que nunca se hace es asumir permiso cuando el archivo sí existe y prohíbe.
 */
final class RobotsTxtGate
{
    public function allows(string $url): bool
    {
        if (! config('newsscraper.scraping.respect_robots_txt', true)) {
            return true;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if ($host === null) {
            return false;
        }

        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return $this->isAllowedBy($this->rulesFor($parts['scheme'] ?? 'https', $host), $path);
    }

    /**
     * Reglas del grupo que aplica a nuestro User-Agent, con caída al grupo `*`.
     *
     * @return array{allow: list<string>, disallow: list<string>}
     */
    private function rulesFor(string $scheme, string $host): array
    {
        return Cache::remember(
            'robots:'.$scheme.':'.$host,
            (int) config('newsscraper.scraping.robots_cache_ttl', 3600),
            function () use ($scheme, $host): array {
                try {
                    $response = Http::withHeaders(['User-Agent' => config('newsscraper.scraping.user_agent')])
                        ->timeout((int) config('newsscraper.scraping.request_timeout', 20))
                        ->get($scheme.'://'.$host.'/robots.txt');
                } catch (Throwable) {
                    return ['allow' => [], 'disallow' => []];
                }

                if (! $response->successful()) {
                    return ['allow' => [], 'disallow' => []];
                }

                return $this->parse($response->body());
            }
        );
    }

    /**
     * @return array{allow: list<string>, disallow: list<string>}
     */
    private function parse(string $robots): array
    {
        $token = strtolower($this->userAgentToken());
        $groups = [];
        $currentAgents = [];
        $startingGroup = true;

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(strtok($line, '#') ?: '');

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                // Varios User-agent seguidos comparten el mismo grupo de reglas.
                if (! $startingGroup) {
                    $currentAgents = [];
                    $startingGroup = true;
                }

                $currentAgents[] = strtolower($value);

                continue;
            }

            if (! in_array($field, ['allow', 'disallow'], true) || $currentAgents === []) {
                continue;
            }

            $startingGroup = false;

            foreach ($currentAgents as $agent) {
                $groups[$agent][$field][] = $value;
            }
        }

        $rules = $groups[$token] ?? $groups['*'] ?? [];

        return [
            'allow' => array_values(array_filter($rules['allow'] ?? [], static fn (string $rule): bool => $rule !== '')),
            'disallow' => array_values(array_filter($rules['disallow'] ?? [], static fn (string $rule): bool => $rule !== '')),
        ];
    }

    /**
     * El nombre del bot sin la versión ni el paréntesis: es lo que un
     * `robots.txt` escribiría si quisiera nombrarnos.
     */
    private function userAgentToken(): string
    {
        $userAgent = (string) config('newsscraper.scraping.user_agent');

        return strtok(strtok($userAgent, ' ') ?: $userAgent, '/') ?: $userAgent;
    }

    /**
     * Gana la regla más específica (la de patrón más largo); si empatan, manda
     * `Allow`, que es lo que dice la especificación de Google.
     *
     * @param  array{allow: list<string>, disallow: list<string>}  $rules
     */
    private function isAllowedBy(array $rules, string $path): bool
    {
        $bestAllow = $this->longestMatch($rules['allow'], $path);
        $bestDisallow = $this->longestMatch($rules['disallow'], $path);

        if ($bestDisallow === null) {
            return true;
        }

        return $bestAllow !== null && $bestAllow >= $bestDisallow;
    }

    /**
     * @param  list<string>  $patterns
     * @return int|null Largo del patrón que matchea, o null si ninguno matchea.
     */
    private function longestMatch(array $patterns, string $path): ?int
    {
        $best = null;

        foreach ($patterns as $pattern) {
            if ($this->matches($pattern, $path)) {
                $best = max($best ?? 0, strlen($pattern));
            }
        }

        return $best;
    }

    /**
     * Soporta los comodines `*` y el ancla final `$`.
     */
    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = $anchored ? substr($pattern, 0, -1) : $pattern;

        $regex = implode('.*', array_map(
            static fn (string $chunk): string => preg_quote($chunk, '#'),
            explode('*', $pattern)
        ));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1;
    }
}
