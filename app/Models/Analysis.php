<?php

namespace App\Models;

use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use Database\Factories\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Salida estructurada del LLM para un artículo.
 *
 * `raw_response` conserva siempre la respuesta cruda junto al análisis parseado:
 * el modelo no es una fuente confiable y hay que poder auditar qué dijo.
 */
#[Fillable([
    'article_id',
    'provider',
    'model',
    'schema_version',
    'summary',
    'category',
    'relevance',
    'importance_explanation',
    'raw_response',
    'analyzed_at',
])]
class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory;

    protected $table = 'analyses';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => NewsCategory::class,
            'relevance' => RelevanceLevel::class,
            'raw_response' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
