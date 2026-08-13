<?php

namespace Database\Factories;

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Entity>
 */
class EntityFactory extends Factory
{
    protected $model = Entity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(EntityType::cases());
        $name = $type === EntityType::Company ? fake()->unique()->company() : fake()->unique()->name();

        return [
            'type' => $type,
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }

    public function company(): static
    {
        $name = fake()->unique()->company();

        return $this->state(fn (): array => [
            'type' => EntityType::Company,
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    public function person(): static
    {
        $name = fake()->unique()->name();

        return $this->state(fn (): array => [
            'type' => EntityType::Person,
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
