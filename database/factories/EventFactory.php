<?php

namespace Database\Factories;

use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Models\Article;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(9), '.');
        $relevance = fake()->randomElement(RelevanceLevel::cases());

        return [
            // El sufijo evita chocar contra el unique cuando dos títulos fake
            // se parecen demasiado.
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'summary' => fake()->paragraph(3),
            'importance' => fake()->paragraph(2),
            'category' => fake()->randomElement(NewsCategory::cases()),
            'relevance' => $relevance,
            'relevance_score' => Event::scoreFor($relevance, 1),
            'tags' => fake()->words(3),
            'first_seen_at' => now()->subHours(fake()->numberBetween(1, 36)),
            'articles_count' => 0,
        ];
    }

    public function withRelevance(RelevanceLevel $relevance): static
    {
        return $this->state(fn (): array => [
            'relevance' => $relevance,
            'relevance_score' => Event::scoreFor($relevance, 1),
        ]);
    }

    public function critical(): static
    {
        return $this->withRelevance(RelevanceLevel::Critical);
    }

    public function ofCategory(NewsCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    /**
     * Acontecimiento cubierto por varios medios: N artículos analizados, cada
     * uno de una fuente distinta. Es la composición que ejercita el "N artículos
     * de M medios" de events/show.blade.php.
     */
    public function withArticles(int $count = 3): static
    {
        return $this->afterCreating(function (Event $event) use ($count): void {
            for ($i = 0; $i < $count; $i++) {
                Article::factory()
                    ->analyzed()
                    ->for(Source::factory())
                    ->for($event)
                    ->create([
                        'published_at' => $event->first_seen_at->addMinutes($i * 37),
                    ]);
            }

            $event->syncAggregatesFromArticles();
        });
    }

    public function withEntities(int $count = 3): static
    {
        return $this->afterCreating(function (Event $event) use ($count): void {
            $event->entities()->attach(
                Entity::factory()->count($count)->create()->pluck('id')
            );
        });
    }
}
