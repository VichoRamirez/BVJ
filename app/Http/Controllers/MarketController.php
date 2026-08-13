<?php

namespace App\Http\Controllers;

use App\Models\MarketSnapshot;
use Illuminate\View\View;

class MarketController extends Controller
{
    /**
     * Panel de mercado. Los gráficos son SVG en línea; el punto de reemplazo si
     * más adelante entra una librería de gráficos es `<x-sparkline>`.
     */
    public function index(): View
    {
        return view('markets.index', [
            'markets' => MarketSnapshot::query()->latestPerSymbol()->get(),
        ]);
    }
}
