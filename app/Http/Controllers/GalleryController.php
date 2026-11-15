<?php

namespace App\Http\Controllers;

use App\Models\ArtPiece;
use App\Models\ArtPieceImage;
use App\Services\CheckoutService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public, no-login side of the site: published, held and sold pieces.
 */
class GalleryController extends Controller
{
    public function index(): Response
    {
        $artPieces = ArtPiece::visibleToPublic()
            ->with(['images' => fn ($query) => $query->orderBy('position')])
            ->latest('id')
            ->paginate(24)
            ->through(fn (ArtPiece $artPiece) => [
                'id' => $artPiece->id,
                'name' => $artPiece->name,
                'price_cents' => $artPiece->price_cents,
                'status' => $artPiece->status->value,
                'status_label' => $artPiece->status->label(),
                'cover_image_url' => $artPiece->images->first()?->url,
            ]);

        return Inertia::render('gallery/Index', [
            'artPieces' => $artPieces,
        ]);
    }

    public function show(ArtPiece $artPiece): Response
    {
        abort_unless($artPiece->status->isPublic(), 404);

        $artPiece->load(['images' => fn ($query) => $query->orderBy('position')]);

        return Inertia::render('gallery/Show', [
            'artPiece' => [
                'id' => $artPiece->id,
                'name' => $artPiece->name,
                'description' => $artPiece->description,
                'price_cents' => $artPiece->price_cents,
                'status' => $artPiece->status->value,
                'status_label' => $artPiece->status->label(),
                'images' => $artPiece->images->map(fn (ArtPieceImage $image) => [
                    'id' => $image->id,
                    'url' => $image->url,
                ])->all(),
            ],
            'stripeKey' => config('services.stripe.key'),
            'holdMinutes' => CheckoutService::HOLD_MINUTES,
        ]);
    }
}
