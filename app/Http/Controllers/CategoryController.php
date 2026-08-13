<?php

namespace App\Http\Controllers;

use App\Enums\NewsCategory;
use App\Models\Event;
use Illuminate\View\View;

class CategoryController extends Controller
{
    /**
     * Acontecimientos de una categoría, del más relevante al menos relevante.
     *
     * El parámetro va tipado como enum: Laravel resuelve el slug de la URL y
     * devuelve 404 solo si no corresponde a ninguna categoría.
     */
    public function show(NewsCategory $category): View
    {
        return view('categories.show', [
            'category' => $category,
            'events' => Event::query()
                ->where('category', $category)
                ->with(['articles.source', 'entities'])
                ->mostRelevant()
                ->get(),
            'categoryCounts' => Event::categoryCounts(),
        ]);
    }
}
