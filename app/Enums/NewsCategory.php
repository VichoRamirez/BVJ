<?php

namespace App\Enums;

/**
 * Categorías financieras cerradas con las que el LLM debe clasificar cada artículo.
 * El valor canónico se persiste y viaja en las respuestas del LLM.
 */
enum NewsCategory: string
{
    case Markets = 'markets';
    case Economy = 'economy';
    case Companies = 'companies';
    case Commodities = 'commodities';
    case Monetary = 'monetary';
    case Regulation = 'regulation';
    case Technology = 'technology';

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

    public function slug(): string
    {
        return match ($this) {
            self::Markets => 'mercados',
            self::Economy => 'economia',
            self::Companies => 'empresas',
            self::Commodities => 'commodities',
            self::Monetary => 'politica-monetaria',
            self::Regulation => 'regulacion',
            self::Technology => 'tecnologia',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $category) {
            if ($category->slug() === $slug) {
                return $category;
            }
        }

        return null;
    }
}
