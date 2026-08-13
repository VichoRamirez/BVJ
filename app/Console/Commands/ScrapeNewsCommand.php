<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeSourceJob;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recolección manual, para desarrollo y para la demo.
 *
 * Por defecto encola un job por fuente; `--sync` los corre en el acto, que es
 * lo cómodo cuando no hay un worker levantado.
 */
class ScrapeNewsCommand extends Command
{
    protected $signature = 'news:scrape
        {--source= : Slug de una fuente concreta; por defecto, todas las activas}
        {--spider= : Clase de spider a usar, ignorando sources.spider_class}
        {--sync : Ejecutar ahora en vez de encolar}';

    protected $description = 'Recolecta artículos de las fuentes configuradas';

    public function handle(): int
    {
        $sources = $this->sources();

        if ($sources->isEmpty()) {
            $this->components->error('No hay fuentes activas que recolectar.');

            return self::FAILURE;
        }

        $spider = $this->option('spider') ?: null;

        foreach ($sources as $source) {
            $job = new ScrapeSourceJob($source->id, $spider);

            if ($this->option('sync')) {
                dispatch_sync($job);
                $this->components->task("Recolectando {$source->name}");
            } else {
                dispatch($job);
                $this->components->twoColumnDetail($source->name, '<fg=gray>encolada</>');
            }
        }

        $this->newLine();
        $this->components->info($sources->count().' fuente(s) procesada(s).');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Source>
     */
    private function sources(): Collection
    {
        $query = Source::query()->where('is_active', true);

        if ($slug = $this->option('source')) {
            // Una fuente pedida a mano se recolecta aunque esté inactiva: es
            // justo lo que se hace para comprobar si ya se recuperó.
            $query = Source::query()->where('slug', $slug);
        }

        return $query->orderBy('name')->get();
    }
}
