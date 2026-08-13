<?php

use App\Models\MarketSnapshot;

it('devuelve solo la última captura de cada instrumento', function () {
    MarketSnapshot::factory()->forInstrument('^IPSA')->create([
        'price' => 6000,
        'captured_at' => now()->subDay(),
    ]);

    MarketSnapshot::factory()->forInstrument('^IPSA')->create([
        'price' => 6842.31,
        'captured_at' => now(),
    ]);

    MarketSnapshot::factory()->forInstrument('CLP=X')->create([
        'captured_at' => now(),
    ]);

    $latest = MarketSnapshot::query()->latestPerSymbol()->get();

    expect($latest)->toHaveCount(2)
        ->and($latest->firstWhere('symbol', '^IPSA')->price)->toBe(6842.31);
});

it('respeta el orden de presentación de la configuración', function () {
    MarketSnapshot::factory()->forInstrument('BTC-USD')->create(['captured_at' => now()]);
    MarketSnapshot::factory()->forInstrument('^IPSA')->create(['captured_at' => now()]);

    expect(MarketSnapshot::query()->latestPerSymbol()->pluck('symbol')->all())
        ->toBe(['^IPSA', 'BTC-USD']);
});

it('conserva el histórico como un arreglo de números', function () {
    $snapshot = MarketSnapshot::factory()->forInstrument('HG=F')->create([
        'history' => [4.62, 4.71, 5.12],
        'captured_at' => now(),
    ]);

    expect($snapshot->fresh()->history)->toBe([4.62, 4.71, 5.12]);
});
