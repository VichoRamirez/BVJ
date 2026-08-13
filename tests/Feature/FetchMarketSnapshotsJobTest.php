<?php

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuote;
use App\Exceptions\MarketDataException;
use App\Jobs\FetchMarketSnapshotsJob;
use App\Models\MarketSnapshot;
use App\Services\Markets\YahooFinanceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
 * Datos de mercado. Yahoo siempre está falseado: ningún test sale a la red.
 */

beforeEach(function (): void {
    config(['newsscraper.markets.instruments' => [
        ['symbol' => '^IPSA', 'name' => 'IPSA', 'detail' => 'Bolsa de Santiago', 'unit' => 'pts'],
        ['symbol' => 'CLP=X', 'name' => 'USD / CLP', 'detail' => 'Dólar observado', 'unit' => '$'],
    ]]);
});

/**
 * Respuesta del endpoint de gráficos con la forma real que devuelve Yahoo.
 *
 * @param  list<float|null>  $closes
 */
function yahooChart(array $closes, float $price, ?int $marketTime = null): array
{
    $marketTime ??= CarbonImmutable::parse('2026-08-12 20:00:00', 'UTC')->timestamp;

    // Una barra diaria por cierre, terminando en el día de `marketTime`.
    $timestamps = [];
    for ($index = count($closes) - 1; $index >= 0; $index--) {
        $timestamps[] = $marketTime - ($index * 86400);
    }

    return [
        'chart' => [
            'result' => [[
                'meta' => [
                    'symbol' => '^IPSA',
                    'regularMarketPrice' => $price,
                    'regularMarketTime' => $marketTime,
                    'exchangeTimezoneName' => 'UTC',
                    // Cierre previo al inicio del rango, no al de la sesión
                    // anterior: el proveedor no debe usarlo para la variación.
                    'chartPreviousClose' => 1.0,
                ],
                'timestamp' => $timestamps,
                'indicators' => ['quote' => [['close' => $closes]]],
            ]],
            'error' => null,
        ],
    ];
}

function fakeYahoo(array $body): void
{
    Http::fake(['query1.finance.yahoo.com/*' => Http::response($body)]);
}

it('captura los instrumentos configurados con su precio, variación e histórico', function () {
    fakeYahoo(yahooChart([100.0, 110.0, 120.0], price: 132.0));

    dispatch_sync(new FetchMarketSnapshotsJob);

    $snapshot = MarketSnapshot::query()->where('symbol', '^IPSA')->sole();

    expect(MarketSnapshot::query()->count())->toBe(2)
        ->and($snapshot->price)->toBe(132.0)
        ->and($snapshot->name)->toBe('IPSA')
        ->and($snapshot->unit)->toBe('pts')
        // La última barra es la del día en curso: se reemplaza por el precio
        // actual en vez de duplicar la sesión.
        //
        // Se compara con toEqual y no con toBe porque la serie va a la base como
        // json: un 132.0 exacto vuelve leído como int, y para el gráfico da lo mismo.
        ->and($snapshot->history)->toEqual([100.0, 110.0, 132.0])
        ->and($snapshot->change_percent)->toBe(20.0);
});

it('respeta el orden de presentación de la configuración', function () {
    fakeYahoo(yahooChart([100.0, 110.0], price: 110.0));

    dispatch_sync(new FetchMarketSnapshotsJob);

    expect(MarketSnapshot::query()->latestPerSymbol()->pluck('name')->all())
        ->toBe(['IPSA', 'USD / CLP']);
});

it('recorta la serie a las sesiones configuradas', function () {
    config(['newsscraper.markets.history_sessions' => 3]);
    fakeYahoo(yahooChart([10.0, 20.0, 30.0, 40.0, 50.0, 60.0], price: 60.0));

    dispatch_sync(new FetchMarketSnapshotsJob);

    expect(MarketSnapshot::query()->first()->history)->toHaveCount(3);
});

it('descarta las sesiones sin datos', function () {
    fakeYahoo(yahooChart([100.0, null, 110.0], price: 121.0));

    dispatch_sync(new FetchMarketSnapshotsJob);

    expect(MarketSnapshot::query()->first()->history)->toEqual([100.0, 121.0]);
});

it('no vuelve a capturar la misma sesión dos veces', function () {
    fakeYahoo(yahooChart([100.0, 110.0], price: 110.0));

    dispatch_sync(new FetchMarketSnapshotsJob);
    dispatch_sync(new FetchMarketSnapshotsJob);

    expect(MarketSnapshot::query()->count())->toBe(2);
});

it('guarda una fila nueva cuando cambia la hora de mercado', function () {
    $today = CarbonImmutable::parse('2026-08-12 20:00:00', 'UTC')->timestamp;
    $tomorrow = CarbonImmutable::parse('2026-08-13 20:00:00', 'UTC')->timestamp;

    // Va por secuencia y no por dos Http::fake() seguidos: el segundo fake no
    // reemplaza al primero, se suma, y para una misma URL gana el stub más viejo.
    // Son dos instrumentos por corrida, o sea cuatro respuestas.
    Http::fakeSequence('query1.finance.yahoo.com/*')
        ->push(yahooChart([100.0, 110.0], price: 110.0, marketTime: $today))
        ->push(yahooChart([100.0, 110.0], price: 110.0, marketTime: $today))
        ->push(yahooChart([100.0, 110.0], price: 115.0, marketTime: $tomorrow))
        ->push(yahooChart([100.0, 110.0], price: 115.0, marketTime: $tomorrow));

    dispatch_sync(new FetchMarketSnapshotsJob);
    dispatch_sync(new FetchMarketSnapshotsJob);

    // El histórico conserva ambas sesiones; la vista solo muestra la última.
    expect(MarketSnapshot::query()->count())->toBe(4)
        ->and(MarketSnapshot::query()->latestPerSymbol()->first()->price)->toBe(115.0);
});

it('sigue capturando los demás instrumentos cuando uno falla', function () {
    app()->instance(MarketDataProvider::class, new class implements MarketDataProvider
    {
        public function fetch(string $symbol, int $sessions): MarketQuote
        {
            if ($symbol === '^IPSA') {
                throw new MarketDataException('Yahoo respondió 404 para ^IPSA.');
            }

            return new MarketQuote($symbol, 950.0, -0.4, [940.0, 950.0], CarbonImmutable::now());
        }
    });

    dispatch_sync(new FetchMarketSnapshotsJob);

    expect(MarketSnapshot::query()->count())->toBe(1)
        ->and(MarketSnapshot::query()->sole()->symbol)->toBe('CLP=X');
});

it('trata un error HTTP de Yahoo como fallo del instrumento', function () {
    Http::fake(['query1.finance.yahoo.com/*' => Http::response([], 429)]);

    dispatch_sync(new FetchMarketSnapshotsJob);

    expect(MarketSnapshot::query()->count())->toBe(0);
});

it('rechaza una respuesta sin la forma esperada', function () {
    Http::fake(['query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => null]])]);

    expect(fn () => app(YahooFinanceProvider::class)->fetch('^IPSA', 10))
        ->toThrow(MarketDataException::class);
});

it('se identifica ante Yahoo con el User-Agent del proyecto', function () {
    fakeYahoo(yahooChart([100.0, 110.0], price: 110.0));

    dispatch_sync(new FetchMarketSnapshotsJob);

    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', config('newsscraper.scraping.user_agent')));
});

it('resuelve el proveedor de Yahoo desde la configuración', function () {
    expect(app(MarketDataProvider::class))->toBeInstanceOf(YahooFinanceProvider::class);
});

it('rechaza un proveedor de mercado no implementado', function () {
    config(['newsscraper.markets.provider' => 'bloomberg-terminal']);

    expect(fn () => app(MarketDataProvider::class))->toThrow(LogicException::class);
});
