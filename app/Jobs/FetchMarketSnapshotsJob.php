<?php

namespace App\Jobs;

use App\Contracts\MarketDataProvider;
use App\Exceptions\MarketDataException;
use App\Models\MarketSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Captura los instrumentos de config('newsscraper.markets.instruments').
 *
 * Mismo criterio que con las fuentes de noticias: cada instrumento falla solo.
 * Que Yahoo no cotice el IPSA un feriado no puede dejar la página de mercados
 * sin dólar ni cobre.
 *
 * La idempotencia va por `(symbol, captured_at)`, y `captured_at` es la hora de
 * mercado que informa Yahoo, no la hora en que corrió el job: repetir la captura
 * dentro de la misma sesión actualiza la fila en vez de agregar otra, pero cada
 * sesión nueva sí deja su propio registro histórico.
 */
class FetchMarketSnapshotsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(MarketDataProvider $provider): void
    {
        $sessions = (int) config('newsscraper.markets.history_sessions', 10);
        $instruments = config('newsscraper.markets.instruments', []);
        $captured = 0;

        foreach (array_values($instruments) as $position => $instrument) {
            try {
                $quote = $provider->fetch($instrument['symbol'], $sessions);
            } catch (MarketDataException $exception) {
                Log::warning('No se pudo capturar un instrumento de mercado.', [
                    'symbol' => $instrument['symbol'],
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            MarketSnapshot::updateOrCreate(
                [
                    'symbol' => $instrument['symbol'],
                    'captured_at' => $quote->capturedAt,
                ],
                [
                    // Los metadatos de presentación se copian al snapshot para
                    // que cada fila del histórico sea autodescriptiva aunque
                    // después cambie la lista de config.
                    'name' => $instrument['name'],
                    'detail' => $instrument['detail'],
                    'unit' => $instrument['unit'],
                    'price' => $quote->price,
                    'change_percent' => $quote->changePercent,
                    'history' => $quote->history,
                    'sort_order' => $position,
                ],
            );

            $captured++;
        }

        Log::info('Captura de mercado terminada.', [
            'captured' => $captured,
            'instruments' => count($instruments),
        ]);
    }
}
