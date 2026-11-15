<?php

use App\Http\Controllers\ArtPieceController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\GalleryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GalleryController::class, 'index'])->name('home');
Route::get('art/{artPiece}', [GalleryController::class, 'show'])->name('gallery.show');
Route::post('art/{artPiece}/checkout', [CheckoutController::class, 'store'])
    ->middleware('throttle:checkout')
    ->name('checkout.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [ArtPieceController::class, 'index'])->name('dashboard');
    Route::post('art-pieces', [ArtPieceController::class, 'store']);
    Route::get('art-pieces/{artPiece}', [ArtPieceController::class, 'show'])->name('art-pieces.show');
    Route::patch('art-pieces/{artPiece}/status', [ArtPieceController::class, 'updateStatus'])->name('art-pieces.status.update');
});

require __DIR__.'/settings.php';
