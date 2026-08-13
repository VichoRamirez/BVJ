<?php

namespace App\Enums;

/**
 * Nivel de relevancia que asigna el análisis. Se muestra siempre con etiqueta de
 * texto además del color: la relevancia nunca se comunica solo por color.
 */
enum RelevanceLevel: string
{
    case Low = 'baja';
    case Medium = 'media';
    case High = 'alta';
    case Critical = 'critica';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Relevancia baja',
            self::Medium => 'Relevancia media',
            self::High => 'Relevancia alta',
            self::Critical => 'Relevancia crítica',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Low => 'Baja',
            self::Medium => 'Media',
            self::High => 'Alta',
            self::Critical => 'Crítica',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    /**
     * Cuántos cuadrados macizos se pintan en el indicador (de 4).
     */
    public function marks(): int
    {
        return $this->weight();
    }

    /**
     * Las de peso alto llevan la marca en acento; las bajas, en tinta apagada.
     */
    public function isProminent(): bool
    {
        return $this->weight() >= self::High->weight();
    }
}
