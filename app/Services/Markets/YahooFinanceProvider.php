<?php

namespace App\Services\Markets;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuote;
use App\Exceptions\MarketDataException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cotizaciones desde el endpoint público de gráficos de Yahoo Finance.
 *
 * No lleva API key: `/v8/finance/chart/{symbol}` es abierto. Lo único que exige
 * es un User-Agent declarado —con el de curl por defecto responde 429—, así que
 * va el mismo identificable que usa el scraping.
 *
 * Se usa el endpoint de gráficos y no el de cotizaciones (`/v7/finance/quote`)
 * por dos razones: el de cotizaciones exige la danza de cookie + crumb, y este
 * devuelve precio, cierre anterior e histórico en una sola llamada, que es
 * exactamente lo que necesita una fila de `market_snapshots`.
 */
class YahooFinanceProvider implements MarketDataProvider
{
    public function fetch(string $symbol, int $sessions): MarketQuote
    {
        $result = $this->requestChart($symbol);
        $meta = $result['meta'] ?? [];
        $price = $meta['regularMarketPrice'] ?? null;

        if (! is_numeric($price)) {
            throw new MarketDataException("Yahoo no devolvió precio para {$symbol}.");
        }

        $capturedAt = CarbonImmutable::createFromTimestamp((int) ($meta['regularMarketTime'] ?? time()), 'UTC');
        $series = $this->closingSeries($result, (float) $price, $capturedAt, (string) ($meta['exchangeTimezoneName'] ?? 'UTC'));

        if ($series === []) {
            throw new MarketDataException("Yahoo no devolvió cierres para {$symbol}.");
        }

        return new MarketQuote(
            symbol: $symbol,
            price: (float) $price,
            changePercent: $this->changePercent($series),
            history: array_slice($series, -$sessions),
            capturedAt: $capturedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestChart(string $symbol): array
    {
        $config = config('newsscraper.markets.yahoo');

        try {
            $response = Http::baseUrl($config['base_url'])
                ->withHeaders(['User-Agent' => config('newsscraper.scraping.user_agent')])
                ->timeout((int) $config['timeout'])
                ->retry((int) $config['retry_attempts'], (int) $config['retry_backoff'], throw: false)
                ->acceptJson()
                ->get('/v8/finance/chart/'.rawurlencode($symbol), [
                    'range' => $config['range'],
                    'interval' => $config['interval'],
                ]);
        } catch (ConnectionException $exception) {
            throw new MarketDataException("No fue posible conectar con Yahoo para {$symbol}.", previous: $exception);
        }

        if ($response->failed()) {
            throw new MarketDataException("Yahoo respondió {$response->status()} para {$symbol}.");
        }

        $result = $response->json('chart.result.0');

        if (! is_array($result)) {
            throw new MarketDataException("La respuesta de Yahoo para {$symbol} no tiene la forma esperada.");
        }

        return $result;
    }

    /**
     * Serie de cierres, del más antiguo al más reciente, con el precio actual
     * como último punto.
     *
     * Yahoo devuelve `null` en las sesiones sin datos —y también en la del día
     * en curso mientras el mercado sigue abierto—, así que se descartan. La
     * última barra, si es la de hoy, se reemplaza por el precio actual en vez de
     * agregarse: si no, el día en curso aparecería dos veces en el gráfico.
     *
     * @param  array<string, mixed>  $result
     * @return list<float>
     */
    private function closingSeries(array $result, float $price, CarbonImmutable $capturedAt, string $exchangeTimezone): array
    {
        $closes = $result['indicators']['quote'][0]['close'] ?? [];
        $timestamps = $result['timestamp'] ?? [];

        if (! is_array($closes) || ! is_array($timestamps)) {
            return [];
        }

        $series = [];
        $lastDay = null;

        foreach ($closes as $index => $close) {
            if (! is_numeric($close) || ! isset($timestamps[$index])) {
                continue;
            }

            $series[] = (float) $close;
            $lastDay = CarbonImmutable::createFromTimestamp((int) $timestamps[$index], $exchangeTimezone)->toDateString();
        }

        if ($series === []) {
            return [];
        }

        if ($lastDay === $capturedAt->timezone($exchangeTimezone)->toDateString()) {
            array_pop($series);
        }

        $series[] = $price;

        return $series;
    }

    /**
     * Variación contra el cierre anterior.
     *
     * No se usa `chartPreviousClose` de la respuesta: ese es el cierre previo al
     * inicio del rango pedido (un mes atrás), no el de la sesión anterior.
     *
     * @param  list<float>  $series
     */
    private function changePercent(array $series): float
    {
        if (count($series) < 2) {
            return 0.0;
        }

        $previous = $series[count($series) - 2];
        $current = $series[count($series) - 1];

        if ($previous == 0.0) {
            return 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}
