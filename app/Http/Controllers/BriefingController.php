<?php

namespace App\Http\Controllers;

use App\Models\Briefing;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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
     * Una edición concreta.
     *
     * Una edición cuya hora de publicación todavía no llega no existe para el
     * público: se responde 404 y no 403, porque decir "existe pero no puedes
     * verla" ya filtra que hay una edición preparada.
     */
    public function show(Briefing $briefing): View
    {
        abort_if($briefing->published_at->isFuture(), Response::HTTP_NOT_FOUND);

        $briefing->load(Briefing::DISPLAY_RELATIONS);

        return view('briefings.show', [
            'briefing' => $briefing,
            'siblings' => Briefing::query()->published()->sameDayAs($briefing)->get(),
        ]);
    }
}
