<?php

namespace App\Http\Controllers;

use App\Support\DemoContent;
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
            'days' => DemoContent::briefings()
                ->groupBy(fn (object $briefing): string => $briefing->published_on->toDateString()),
        ]);
    }

    /**
     * Una edición concreta. Con modelos reales esto será route model binding.
     */
    public function show(int $briefing): View
    {
        $edition = DemoContent::findBriefing($briefing);

        abort_if($edition === null, Response::HTTP_NOT_FOUND);

        return view('briefings.show', [
            'briefing' => $edition,
            'siblings' => DemoContent::briefings()
                ->where('published_on', $edition->published_on)
                ->sortBy('published_at')
                ->values(),
        ]);
    }
}
