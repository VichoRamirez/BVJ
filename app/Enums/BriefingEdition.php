<?php

namespace App\Enums;

/**
 * Las dos ediciones diarias. La hora es la de publicación programada en
 * America/Santiago (ver el scheduler del pipeline).
 */
enum BriefingEdition: string
{
    case Morning = 'morning';
    case Evening = 'evening';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Edición de la mañana',
            self::Evening => 'Edición de la tarde',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Morning => 'Mañana',
            self::Evening => 'Tarde',
        };
    }

    public function scheduledHour(): int
    {
        return match ($this) {
            self::Morning => 7,
            self::Evening => 18,
        };
    }
}
