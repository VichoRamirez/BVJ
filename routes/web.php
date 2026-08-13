<?php

use App\Http\Controllers\BriefingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MarketController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/briefings', [BriefingController::class, 'index'])->name('briefings.index');
Route::get('/briefings/{briefing}', [BriefingController::class, 'show'])->name('briefings.show');

Route::get('/eventos/{event}', [EventController::class, 'show'])->name('events.show');

Route::get('/categorias/{category}', [CategoryController::class, 'show'])->name('categories.show');

Route::get('/mercados', [MarketController::class, 'index'])->name('markets.index');
