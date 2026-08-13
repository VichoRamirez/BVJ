<?php

namespace App\Contracts;

use App\Data\MarketQuote;
use App\Exceptions\MarketDataException;

/**
 * Fuente de datos de mercado.
 *
 * Igual que con `NewsAnalyzer`, el pipeline habla con el contrato y no con el
 * proveedor: cambiar Yahoo por otro servicio es escribir una clase y cambiar
 * `config('newsscraper.markets.provider')`.
 */
interface MarketDataProvider
{
    /**
     * @param  int  $sessions  Cuántos cierres devolver en la serie histórica.
     *
     * @throws MarketDataException Cuando el símbolo no se pudo cotizar.
     */
    public function fetch(string $symbol, int $sessions): MarketQuote;
}
