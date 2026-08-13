<?php

namespace App\Exceptions;

class ArticleInputTooLargeException extends NewsAnalysisException
{
    public function __construct(public readonly string $field, public readonly int $maxLength)
    {
        parent::__construct("El campo {$field} supera el máximo permitido de {$maxLength} caracteres.");
    }
}
