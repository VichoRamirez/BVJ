<?php

namespace Database\Factories;

use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Models\Analysis;
use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Analysis>
 */
class AnalysisFactory extends Factory
{
    protected $model = Analysis::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $summary = fake()->paragraph(3);
        $importance = fake()->paragraph(2);
        $category = fake()->randomElement(NewsCategory::cases());
        $relevance = fake()->randomElement(RelevanceLevel::cases());

        return [
            'article_id' => Article::factory(),
            'provider' => 'ollama',
            'model' => 'gpt-oss:20b-cloud',
            'schema_version' => '1.0',
            'summary' => $summary,
            'category' => $category,
            'relevance' => $relevance,
            'importance_explanation' => $importance,
            'raw_response' => [
                'content' => $summary,
                'payload' => [
                    'summary' => $summary,
                    'category' => $category->value,
                    'relevance' => $relevance->value,
                    'companies' => [fake()->company()],
                    'people' => [fake()->name()],
                    'tags' => fake()->words(3),
                    'importance_explanation' => $importance,
                ],
            ],
            'analyzed_at' => now(),
        ];
    }

    public function ofCategory(NewsCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    public function withRelevance(RelevanceLevel $relevance): static
    {
        return $this->state(fn (): array => ['relevance' => $relevance]);
    }
}
