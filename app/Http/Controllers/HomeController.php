<?php

namespace App\Http\Controllers;

use App\Support\DemoContent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Portada: el último briefing publicado.
     *
     * Mientras no exista el pipeline, los datos vienen de `DemoContent`. Al
     * conectar la base de datos esto pasa a ser una consulta con eager loading
     * (`Briefing::with('events.articles.source')->latest('published_at')->first()`)
     * y la vista no cambia.
     */
    public function __invoke(Request $request): View
    {
        // `?vacio=1` fuerza el estado sin briefing, para poder revisarlo en diseño.
        $briefing = $request->boolean('vacio') ? null : DemoContent::latestBriefing();

        return view('home', [
            'briefing' => $briefing,
            'siblings' => $briefing
                ? DemoContent::briefings()->where('published_on', $briefing->published_on)->sortBy('published_at')->values()
                : collect(),
            'markets' => DemoContent::markets(),
            'sources' => DemoContent::sources(),
            'categoryCounts' => DemoContent::categoryCounts(),
        ]);
    }
}
