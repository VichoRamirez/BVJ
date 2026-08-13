<?php

namespace App\Http\Controllers;

use App\Enums\NewsCategory;
use App\Support\DemoContent;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CategoryController extends Controller
{
    /**
     * Acontecimientos de una categoría, del más relevante al menos relevante.
     */
    public function show(string $category): View
    {
        $newsCategory = NewsCategory::tryFrom($category);

        abort_if($newsCategory === null, Response::HTTP_NOT_FOUND);

        return view('categories.show', [
            'category' => $newsCategory,
            'events' => DemoContent::eventsByCategory($newsCategory),
            'categoryCounts' => DemoContent::categoryCounts(),
        ]);
    }
}
