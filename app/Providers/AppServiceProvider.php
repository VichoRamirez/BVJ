<?php

namespace App\Providers;

use App\Contracts\NewsAnalyzer;
use App\Services\Ai\FakeNewsAnalyzer;
use App\Services\Ai\OllamaAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(NewsAnalyzer::class, function (): NewsAnalyzer {
            $driver = config('newsscraper.ai.driver');

            if ($driver === 'fake' && app()->environment(['local', 'testing'])) {
                return new FakeNewsAnalyzer;
            }

            if ($driver === 'ollama') {
                return app(OllamaAnalyzer::class);
            }

            throw new LogicException(
                $driver === 'fake'
                    ? 'El driver fake solo está permitido en los entornos local y testing.'
                    : 'No hay un driver de análisis implementado para la configuración actual.'
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fechas inmutables en todo el dominio y nombres de día/mes en español.
        // Se guarda en UTC y se muestra en America/Santiago (ver CLAUDE.md §4).
        Date::use(CarbonImmutable::class);
        Carbon::setLocale(config('app.locale'));
        CarbonImmutable::setLocale(config('app.locale'));
    }
}
