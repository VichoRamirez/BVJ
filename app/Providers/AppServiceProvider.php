<?php

namespace App\Providers;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsAnalyzer;
use App\Data\AnalyzerCandidate;
use App\Services\Ai\FakeNewsAnalyzer;
use App\Services\Ai\FallbackNewsAnalyzer;
use App\Services\Ai\OllamaAnalyzer;
use App\Services\Ai\OpenRouterAnalyzer;
use App\Services\Markets\YahooFinanceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
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

            if ($driver === 'chain') {
                return new FallbackNewsAnalyzer($this->analyzerChain());
            }

            return $this->analyzerFor(['driver' => $driver]);
        });

        $this->app->bind(MarketDataProvider::class, function (): MarketDataProvider {
            $provider = config('newsscraper.markets.provider');

            if ($provider === 'yahoo') {
                return app(YahooFinanceProvider::class);
            }

            throw new LogicException('No hay un proveedor de datos de mercado implementado para la configuración actual.');
        });
    }

    /**
     * Los eslabones de la cadena, sin construir.
     *
     * Cada uno va detrás de un closure porque los constructores validan y lanzan
     * si falta configuración: construirlos todos por adelantado haría que un
     * eslabón sin API key tumbara también a los que sí funcionan.
     *
     * @return list<AnalyzerCandidate>
     */
    private function analyzerChain(): array
    {
        $chain = (array) config('newsscraper.ai.chain', []);

        if ($chain === []) {
            throw new LogicException('NEWS_AI_DRIVER=chain requiere al menos un eslabón en config newsscraper.ai.chain.');
        }

        return array_values(array_map(
            fn (array $link): AnalyzerCandidate => new AnalyzerCandidate(
                label: $link['driver'].(isset($link['model']) ? ':'.$link['model'] : ''),
                resolve: fn (): NewsAnalyzer => $this->analyzerFor($link),
            ),
            $chain,
        ));
    }

    /**
     * @param  array{driver?: string|null, model?: string|null}  $link
     */
    private function analyzerFor(array $link): NewsAnalyzer
    {
        return match ($link['driver'] ?? null) {
            'ollama' => app(OllamaAnalyzer::class),
            'openrouter' => new OpenRouterAnalyzer($link['model'] ?? null),
            'fake' => app()->environment(['local', 'testing'])
                ? new FakeNewsAnalyzer
                : throw new LogicException('El driver fake solo está permitido en los entornos local y testing.'),
            default => throw new LogicException('No hay un driver de análisis implementado para la configuración actual.'),
        };
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
