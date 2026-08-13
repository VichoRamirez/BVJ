<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'base_url' => 'https://'.Str::slug($name).'.cl',
            'spider_class' => null,
            'is_active' => true,
            'last_scraped_at' => now(),
            'failure_count' => 0,
            'last_failure_reason' => null,
        ];
    }

    /**
     * Fuente caída: alimenta el aviso de <x-source-status> en la portada.
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'failure_count' => 3,
            'last_failure_reason' => 'La fuente no respondió en la última recolección.',
        ]);
    }

    public function failing(int $times = 1): static
    {
        return $this->state(fn (): array => [
            'failure_count' => $times,
            'last_failure_reason' => 'Respuesta inesperada del servidor.',
        ]);
    }
}
