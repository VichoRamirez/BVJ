<?php

namespace App\Support;

use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;

/**
 * Esquema JSON que se le exige al modelo.
 *
 * Vive en un solo lugar porque lo usan todos los proveedores: Ollama lo manda
 * como `format` y OpenRouter como `response_format.json_schema`. Si estuviera
 * duplicado, agregar una categoría al enum arreglaría un proveedor y dejaría el
 * otro pidiendo un enum viejo.
 *
 * Las categorías y relevancias salen de los enums, no de una lista escrita a
 * mano: el modelo no puede devolver un valor que el dominio no acepte.
 */
final class AnalysisJsonSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return once(fn (): array => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary' => ['type' => 'string'],
                'category' => ['type' => 'string', 'enum' => array_column(NewsCategory::cases(), 'value')],
                'relevance' => ['type' => 'string', 'enum' => array_column(RelevanceLevel::cases(), 'value')],
                'companies' => ['type' => 'array', 'items' => ['type' => 'string']],
                'people' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'importance_explanation' => ['type' => 'string'],
            ],
            'required' => ['summary', 'category', 'relevance', 'companies', 'people', 'tags', 'importance_explanation'],
        ]);
    }
}
