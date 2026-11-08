<?php

use App\Http\Controllers\ArtPieceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [ArtPieceController::class, 'index'])->name('dashboard');
    Route::post('art-pieces', [ArtPieceController::class, 'store']);
});

require __DIR__.'/settings.php';
