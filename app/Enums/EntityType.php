<?php

namespace App\Enums;

/**
 * Tipo de entidad mencionada en un artículo. Se distingue por icono, no por color.
 */
enum EntityType: string
{
    case Company = 'company';
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Empresa',
            self::Person => 'Persona',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Company => 'Empresas mencionadas',
            self::Person => 'Personas mencionadas',
        };
    }
}
