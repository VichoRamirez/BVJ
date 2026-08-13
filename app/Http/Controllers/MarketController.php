<?php

namespace App\Http\Controllers;

use App\Models\MarketSnapshot;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketController extends Controller
{
    /**
     * Panel de mercado. Los gráficos son SVG en línea; el punto de reemplazo si
     * más adelante entra una librería de gráficos es `<x-sparkline>`.
     */
    public function index(Request $request): View
    {
        // `?vacio=1` fuerza el estado sin capturas, para revisarlo en diseño
        // sin tener que vaciar la tabla.
        return view('markets.index', [
            'markets' => $request->boolean('vacio')
                ? collect()
                : MarketSnapshot::query()->latestPerSymbol()->get(),
        ]);
    }
}
