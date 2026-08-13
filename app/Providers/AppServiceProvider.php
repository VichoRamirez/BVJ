<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
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

        // Las vistas del briefing recorren briefing → events → articles → source.
        // Si a un controller le falta un eager load, esto lo hace explotar en
        // local y en los tests en vez de esconderlo como un N+1 silencioso.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
