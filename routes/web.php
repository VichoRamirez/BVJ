<?php

use App\Http\Controllers\BriefingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MarketController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/briefings', [BriefingController::class, 'index'])->name('briefings.index');
Route::get('/briefings/{briefing}', [BriefingController::class, 'show'])
    ->whereNumber('briefing')
    ->name('briefings.show');

Route::get('/eventos/{event}', [EventController::class, 'show'])
    ->where('event', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('events.show');

Route::get('/categorias/{category}', [CategoryController::class, 'show'])
    ->where('category', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('categories.show');

Route::get('/mercados', [MarketController::class, 'index'])->name('markets.index');
