<?php

namespace App\Http\Controllers;

use App\Models\Briefing;
use App\Models\Event;
use App\Models\MarketSnapshot;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Portada: el último briefing publicado.
     */
    public function __invoke(Request $request): View
    {
        // `?vacio=1` fuerza el estado sin briefing, para poder revisarlo en
        // diseño sin tener que vaciar la base.
        $briefing = $request->boolean('vacio')
            ? null
            : Briefing::query()
                ->with(Briefing::DISPLAY_RELATIONS)
                ->latest('published_at')
                ->first();

        return view('home', [
            'briefing' => $briefing,
            'siblings' => $briefing
                ? Briefing::query()->sameDayAs($briefing)->get()
                : collect(),
            'markets' => MarketSnapshot::query()->latestPerSymbol()->get(),
            'sources' => Source::query()->orderBy('name')->get(),
            'categoryCounts' => Event::categoryCounts(),
        ]);
    }
}
