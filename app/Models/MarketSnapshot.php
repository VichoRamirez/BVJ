<?php

namespace App\Models;

use Database\Factories\MarketSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Captura de un instrumento en un momento dado (Yahoo Finance).
 */
#[Fillable([
    'symbol',
    'name',
    'detail',
    'unit',
    'price',
    'change_percent',
    'history',
    'sort_order',
    'captured_at',
])]
class MarketSnapshot extends Model
{
    /** @use HasFactory<MarketSnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'change_percent' => 'float',
            'history' => 'array',
            'sort_order' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * La última captura de cada instrumento, en el orden de presentación
     * definido en config('newsscraper.markets.instruments').
     *
     * @param  Builder<static>  $query
     */
    public function scopeLatestPerSymbol(Builder $query): void
    {
        $query->whereIn('id', function ($subquery): void {
            $subquery->selectRaw('max(id)')
                ->from('market_snapshots')
                ->groupBy('symbol');
        })
            ->orderBy('sort_order')
            ->orderBy('symbol');
    }
}
