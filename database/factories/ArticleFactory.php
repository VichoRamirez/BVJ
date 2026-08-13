<?php

namespace Database\Factories;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Event;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(8), '.');

        return [
            'source_id' => Source::factory(),
            'event_id' => null,
            'url' => 'https://'.fake()->domainName().'/'.Str::slug($title).'-'.fake()->unique()->numberBetween(1000, 999999),
            'title' => $title,
            'author' => fake()->name(),
            'published_at' => now()->subHours(fake()->numberBetween(1, 48)),
            'excerpt' => fake()->paragraph(2),
            'content' => fake()->paragraphs(4, true),
            'scraped_at' => now(),
            'analysis_status' => AnalysisStatus::Pending,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'analysis_status' => AnalysisStatus::Pending,
        ]);
    }

    /**
     * Artículo ya analizado, con su Analysis asociado.
     */
    public function analyzed(): static
    {
        return $this->state(fn (): array => [
            'analysis_status' => AnalysisStatus::Completed,
        ])->has(Analysis::factory(), 'analysis');
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'analysis_status' => AnalysisStatus::Failed,
        ]);
    }

    public function withoutAuthor(): static
    {
        return $this->state(fn (): array => ['author' => null]);
    }

    public function fromSource(Source $source): static
    {
        return $this->state(fn (): array => ['source_id' => $source->id]);
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (): array => ['event_id' => $event->id]);
    }
}
