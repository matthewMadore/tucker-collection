<?php

use App\Http\Controllers\ArtPieceController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GalleryController::class, 'index'])->name('home');
Route::get('art/{artPiece}', [GalleryController::class, 'show'])->name('gallery.show');
Route::post('art/{artPiece}/checkout', [CheckoutController::class, 'store'])
    ->middleware('throttle:checkout')
    ->name('checkout.store');
Route::get('checkout/complete', [CheckoutController::class, 'complete'])->name('checkout.complete');
Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('signed')->name('orders.show');
Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [ArtPieceController::class, 'index'])->name('dashboard');
    Route::post('art-pieces', [ArtPieceController::class, 'store']);
    Route::get('art-pieces/{artPiece}', [ArtPieceController::class, 'show'])->name('art-pieces.show');
    Route::patch('art-pieces/{artPiece}/status', [ArtPieceController::class, 'updateStatus'])->name('art-pieces.status.update');
    Route::get('sales', [SalesController::class, 'index'])->name('sales.index');
    Route::post('sales/{order}/ship', [SalesController::class, 'ship'])->name('sales.ship');
    Route::post('sales/{order}/refund', [SalesController::class, 'refund'])->name('sales.refund');
});

require __DIR__.'/settings.php';
