<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Cortocircuito por eslabón de la cadena.
 *
 * Sin esto, un proveedor caído se reintenta una vez por artículo: con 40
 * artículos y 60 s de timeout son 40 minutos de espera pura antes de que cada
 * uno llegue al respaldo. Tras N fallos seguidos el eslabón se salta durante un
 * rato, y el primer éxito lo restablece.
 *
 * El estado va en caché y no en memoria porque los artículos se analizan en
 * jobs distintos, cada uno en su propio proceso.
 */
final class AnalyzerCircuitBreaker
{
    public function isOpen(string $label): bool
    {
        return $this->failures($label) >= $this->threshold();
    }

    public function recordFailure(string $label): void
    {
        Cache::put($this->key($label), $this->failures($label) + 1, $this->ttl());
    }

    public function recordSuccess(string $label): void
    {
        Cache::forget($this->key($label));
    }

    public function failures(string $label): int
    {
        return (int) Cache::get($this->key($label), 0);
    }

    private function threshold(): int
    {
        return max(1, (int) config('newsscraper.ai.fallback.circuit_breaker_failures', 3));
    }

    private function ttl(): int
    {
        return max(1, (int) config('newsscraper.ai.fallback.circuit_breaker_ttl', 300));
    }

    private function key(string $label): string
    {
        return 'analyzer-circuit:'.$label;
    }
}
