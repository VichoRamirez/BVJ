<?php

namespace App\Enums;

/**
 * Categorías financieras cerradas con las que el LLM debe clasificar cada artículo.
 * El valor es el slug que viaja en la URL; la etiqueta es lo que ve el lector.
 */
enum NewsCategory: string
{
    case Markets = 'mercados';
    case Economy = 'economia';
    case Companies = 'empresas';
    case Commodities = 'commodities';
    case Monetary = 'politica-monetaria';
    case Regulation = 'regulacion';
    case Technology = 'tecnologia';

    public function label(): string
    {
        return match ($this) {
            self::Markets => 'Mercados',
            self::Economy => 'Economía',
            self::Companies => 'Empresas',
            self::Commodities => 'Commodities',
            self::Monetary => 'Política monetaria',
            self::Regulation => 'Regulación',
            self::Technology => 'Tecnología',
        };
    }

    /**
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }
}
