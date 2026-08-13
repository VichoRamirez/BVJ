<?php

namespace App\Console\Commands;

use App\Jobs\FetchMarketSnapshotsJob;
use App\Models\MarketSnapshot;
use Illuminate\Console\Command;

/**
 * Captura manual de datos de mercado, para desarrollo y para la demo.
 */
class FetchMarketsCommand extends Command
{
    protected $signature = 'news:markets {--queue : Encolar en vez de ejecutar ahora}';

    protected $description = 'Captura las cotizaciones de los instrumentos seguidos';

    public function handle(): int
    {
        if ($this->option('queue')) {
            FetchMarketSnapshotsJob::dispatch();
            $this->components->info('Captura encolada.');

            return self::SUCCESS;
        }

        dispatch_sync(new FetchMarketSnapshotsJob);

        $snapshots = MarketSnapshot::query()->latestPerSymbol()->get();

        if ($snapshots->isEmpty()) {
            $this->components->error('No se pudo capturar ningún instrumento. Revisa el log.');

            return self::FAILURE;
        }

        foreach ($snapshots as $snapshot) {
            $this->components->twoColumnDetail(
                $snapshot->name,
                sprintf('%s %s · %+.2f%%', number_format($snapshot->price, 2, ',', '.'), $snapshot->unit, $snapshot->change_percent)
            );
        }

        $this->newLine();
        $this->components->info($snapshots->count().' instrumento(s) capturado(s).');

        return self::SUCCESS;
    }
}
