<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
