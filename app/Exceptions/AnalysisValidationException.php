<?php

namespace App\Exceptions;

class AnalysisValidationException extends NewsAnalysisException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('La respuesta del análisis no cumple el esquema esperado.');
    }
}
