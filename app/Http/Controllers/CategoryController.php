<?php

namespace App\Http\Controllers;

use App\Enums\NewsCategory;
use App\Models\Event;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    /**
     * Acontecimientos de una categoría, del más relevante al menos relevante.
     *
     * La URL viaja con el slug en español (`/categorias/politica-monetaria`),
     * mientras que el valor persistido del enum es en inglés: por eso el
     * parámetro entra como string y se resuelve con `fromSlug()`.
     */
    public function show(string $category): View
    {
        $newsCategory = NewsCategory::fromSlug($category);

        abort_if($newsCategory === null, Response::HTTP_NOT_FOUND);

        return view('categories.show', [
            'category' => $newsCategory,
            'events' => Event::query()
                ->published()
                ->where('category', $newsCategory)
                ->with(['articles.source', 'entities'])
                ->mostRelevant()
                ->get(),
            'categoryCounts' => Event::categoryCounts(),
        ]);
    }
}
