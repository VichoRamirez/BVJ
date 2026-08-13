<?php

namespace App\Http\Controllers;

use App\Support\DemoContent;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class EventController extends Controller
{
    /**
     * Un acontecimiento con todos sus artículos y fuentes.
     */
    public function show(string $event): View
    {
        $acontecimiento = DemoContent::findEvent($event);

        abort_if($acontecimiento === null, Response::HTTP_NOT_FOUND);

        return view('events.show', [
            'event' => $acontecimiento,
            'related' => DemoContent::eventsByCategory($acontecimiento->category)
                ->where('slug', '!=', $acontecimiento->slug)
                ->take(3),
        ]);
    }
}
