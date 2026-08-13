<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\View\View;

class EventController extends Controller
{
    /**
     * Un acontecimiento con todos sus artículos y fuentes.
     *
     * El binding resuelve por slug (Event::getRouteKeyName), así que el 404 de
     * un acontecimiento inexistente lo da el router.
     */
    public function show(Event $event): View
    {
        $event->load([
            'articles' => fn ($query) => $query->with('source')->orderBy('published_at'),
            'entities',
        ]);

        return view('events.show', [
            'event' => $event,
            'related' => Event::query()
                ->where('category', $event->category)
                ->whereKeyNot($event->getKey())
                ->mostRelevant()
                ->limit(3)
                ->get(),
        ]);
    }
}
