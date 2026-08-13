<?php

namespace App\Http\Controllers;

use App\Support\DemoContent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketController extends Controller
{
    /**
     * Panel de mercado. Los gráficos son SVG en línea mientras no se instale
     * laravel-charts; el punto de reemplazo es `<x-sparkline>`.
     */
    public function index(Request $request): View
    {
        return view('markets.index', [
            'markets' => $request->boolean('vacio') ? collect() : DemoContent::markets(),
        ]);
    }
}
