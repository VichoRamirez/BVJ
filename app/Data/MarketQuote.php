<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Cotización de un instrumento tal como la devuelve el proveedor de datos.
 *
 * Solo trae lo que la vista necesita: precio, variación y la serie de cierres
 * para el sparkline. Los metadatos de presentación (nombre, unidad, orden) no
 * vienen de acá sino de config('newsscraper.markets.instruments'), porque son
 * decisión nuestra y no del proveedor.
 */
readonly class MarketQuote
{
    /**
     * @param  list<float>  $history  Cierres de las últimas sesiones, del más antiguo al más reciente.
     */
    public function __construct(
        public string $symbol,
        public float $price,
        public float $changePercent,
        public array $history,
        public CarbonImmutable $capturedAt,
    ) {
        if (trim($symbol) === '') {
            throw new InvalidArgumentException('El símbolo del instrumento no puede estar vacío.');
        }

        if ($history === []) {
            throw new InvalidArgumentException('La serie histórica no puede estar vacía.');
        }
    }
}
