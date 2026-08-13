<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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
        // Un acontecimiento es público solo si alguna edición ya publicada lo
        // incluye: existir en la base no alcanza. Si no, el contenido de la
        // edición de la tarde sería accesible por URL desde la mañana.
        abort_unless(
            Event::query()->whereKey($event->getKey())->published()->exists(),
            Response::HTTP_NOT_FOUND
        );

        $event->load([
            'articles' => fn ($query) => $query->with('source')->orderBy('published_at'),
            'entities',
        ]);

        return view('events.show', [
            'event' => $event,
            'related' => Event::query()
                ->published()
                ->where('category', $event->category)
                ->whereKeyNot($event->getKey())
                ->mostRelevant()
                ->limit(3)
                ->get(),
        ]);
    }
}
