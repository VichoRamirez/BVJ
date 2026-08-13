<?php

namespace App\Exceptions;

class AnalysisResponseTooLargeException extends NewsAnalysisException
{
    public function __construct(public readonly int $maxLength)
    {
        parent::__construct("La respuesta del análisis supera el máximo permitido de {$maxLength} caracteres.");
    }
}
