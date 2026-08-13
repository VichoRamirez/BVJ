<?php

namespace Database\Factories;

use App\Models\MarketSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketSnapshot>
 */
class MarketSnapshotFactory extends Factory
{
    protected $model = MarketSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var list<array{symbol: string, name: string, detail: string, unit: string}> $instruments */
        $instruments = config('newsscraper.markets.instruments');
        $index = fake()->numberBetween(0, count($instruments) - 1);
        $instrument = $instruments[$index];

        $price = fake()->randomFloat(2, 10, 7000);

        return [
            ...$instrument,
            'price' => $price,
            'change_percent' => fake()->randomFloat(2, -5, 5),
            'history' => $this->historyAround($price),
            'sort_order' => $index,
            'captured_at' => now(),
        ];
    }

    public function forInstrument(string $symbol): static
    {
        /** @var list<array{symbol: string, name: string, detail: string, unit: string}> $instruments */
        $instruments = config('newsscraper.markets.instruments');

        foreach ($instruments as $index => $instrument) {
            if ($instrument['symbol'] === $symbol) {
                return $this->state(fn (): array => [...$instrument, 'sort_order' => $index]);
            }
        }

        return $this->state(fn (): array => ['symbol' => $symbol]);
    }

    /**
     * @return list<float>
     */
    private function historyAround(float $price): array
    {
        $sessions = (int) config('newsscraper.markets.history_sessions', 10);

        return collect(range(1, $sessions))
            ->map(fn (int $session): float => round($price * (1 + fake()->randomFloat(4, -0.06, 0.06)), 2))
            ->all();
    }
}
