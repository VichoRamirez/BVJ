<?php

namespace Database\Factories;

use App\Enums\BriefingEdition;
use App\Models\Briefing;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Briefing>
 */
class BriefingFactory extends Factory
{
    protected $model = Briefing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // unique() sobre la fecha evita chocar contra unique(published_on, edition).
        $day = CarbonImmutable::parse(fake()->unique()->dateTimeBetween('-60 days', 'now'))->startOfDay();
        $edition = fake()->randomElement(BriefingEdition::cases());

        return [
            'edition' => $edition,
            'published_on' => $day,
            'published_at' => $day->setTime($edition->scheduledHour(), 0),
        ];
    }

    public function morning(): static
    {
        return $this->edition(BriefingEdition::Morning);
    }

    public function evening(): static
    {
        return $this->edition(BriefingEdition::Evening);
    }

    public function edition(BriefingEdition $edition): static
    {
        return $this->state(fn (array $attributes): array => [
            'edition' => $edition,
            'published_at' => CarbonImmutable::parse($attributes['published_on'])
                ->setTime($edition->scheduledHour(), 0),
        ]);
    }

    public function on(CarbonImmutable $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_on' => $date->startOfDay(),
            'published_at' => $date->startOfDay()
                ->setTime($attributes['edition']->scheduledHour(), 0),
        ]);
    }

    /**
     * Engancha N acontecimientos en orden de relevancia, que es como los arma
     * GenerateBriefingJob.
     */
    public function withEvents(int $count = 7): static
    {
        return $this->afterCreating(function (Briefing $briefing) use ($count): void {
            $events = Event::factory()->count($count)->create()
                ->sortByDesc('relevance_score')
                ->values();

            $briefing->events()->attach(
                $events->mapWithKeys(fn (Event $event, int $index): array => [
                    $event->id => ['position' => $index + 1],
                ])->all()
            );
        });
    }
}
