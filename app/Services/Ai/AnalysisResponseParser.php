<?php

namespace App\Services\Ai;

use App\Data\AnalysisResult;
use App\Data\NewsAnalysisLimits;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use App\Exceptions\AnalysisParseException;
use App\Exceptions\AnalysisResponseTooLargeException;
use App\Exceptions\AnalysisValidationException;
use Illuminate\Support\Facades\Validator;
use JsonException;

class AnalysisResponseParser
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $schemaVersion = '1.0',
        private readonly NewsAnalysisLimits $limits = new NewsAnalysisLimits,
    ) {
        if ($provider === '' || $model === '' || $schemaVersion === '') {
            throw new \InvalidArgumentException('El provider, modelo y schema version no pueden estar vacíos.');
        }
    }

    public function parse(string $response): AnalysisResult
    {
        if (mb_strlen($response) > $this->limits->response) {
            throw new AnalysisResponseTooLargeException($this->limits->response);
        }

        $response = $this->removeMarkdownFence($response);

        try {
            $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AnalysisParseException('La respuesta no contiene JSON válido.', previous: $exception);
        }

        if (! is_array($payload) || $payload === [] || array_is_list($payload)) {
            throw new AnalysisValidationException(['response' => ['La respuesta debe ser un objeto JSON no vacío.']]);
        }

        $validator = Validator::make($payload, [
            'summary' => ['required', 'string', 'filled', 'max:2000'],
            'category' => ['required', 'string', 'in:'.implode(',', array_column(NewsCategory::cases(), 'value'))],
            'relevance' => ['required', 'string', 'in:'.implode(',', array_column(RelevanceLevel::cases(), 'value'))],
            'companies' => ['present', 'array', 'max:'.$this->limits->companies],
            'companies.*' => ['required', 'string', 'filled', 'max:200'],
            'people' => ['present', 'array', 'max:'.$this->limits->people],
            'people.*' => ['required', 'string', 'filled', 'max:200'],
            'tags' => ['present', 'array', 'max:'.$this->limits->tags],
            'tags.*' => ['required', 'string', 'filled', 'max:100'],
            'importance_explanation' => ['required', 'string', 'filled', 'max:2000'],
        ]);

        $errors = $validator->errors()->toArray();
        $unexpectedKeys = array_diff(array_keys($payload), [
            'summary',
            'category',
            'relevance',
            'companies',
            'people',
            'tags',
            'importance_explanation',
        ]);

        if ($unexpectedKeys !== []) {
            $errors['response'][] = 'La respuesta contiene claves no permitidas.';
        }

        foreach (['companies', 'people', 'tags'] as $listKey) {
            if (isset($payload[$listKey]) && is_array($payload[$listKey]) && ! array_is_list($payload[$listKey])) {
                $errors[$listKey][] = 'La lista debe ser un array JSON indexado.';
            }
        }

        if ($errors !== []) {
            throw new AnalysisValidationException($errors);
        }

        return new AnalysisResult(
            summary: $payload['summary'],
            category: NewsCategory::from($payload['category']),
            relevance: RelevanceLevel::from($payload['relevance']),
            companies: array_values($payload['companies']),
            people: array_values($payload['people']),
            tags: array_values($payload['tags']),
            importanceExplanation: $payload['importance_explanation'],
            provider: $this->provider,
            model: $this->model,
            schemaVersion: $this->schemaVersion,
        );
    }

    private function removeMarkdownFence(string $response): string
    {
        $response = trim($response);

        if (preg_match('/^```(?:json)?\s*\R(?<json>.*?)\R```$/si', $response, $matches) === 1) {
            return trim($matches['json']);
        }

        return $response;
    }
}
