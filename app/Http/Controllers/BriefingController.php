<?php

namespace App\Http\Controllers;

use App\Models\Briefing;
use Illuminate\View\View;

class BriefingController extends Controller
{
    /**
     * Histórico de ediciones, agrupado por día.
     */
    public function index(): View
    {
        return view('briefings.index', [
            'days' => Briefing::query()
                ->published()
                ->with('events')
                ->orderByDesc('published_at')
                ->get()
                ->groupBy(fn (Briefing $briefing): string => $briefing->published_on->toDateString()),
        ]);
    }

    /**
     * Una edición concreta. El 404 lo da el route model binding.
     */
    public function show(Briefing $briefing): View
    {
        $briefing->load(Briefing::DISPLAY_RELATIONS);

        return view('briefings.show', [
            'briefing' => $briefing,
            'siblings' => Briefing::query()->published()->sameDayAs($briefing)->get(),
        ]);
    }
}
