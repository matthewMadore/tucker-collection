<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ArtPieceController;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::post('/art-pieces', [ArtPieceController::class, 'store']);
require __DIR__.'/settings.php';
