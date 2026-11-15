<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\ArtPiece;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    /**
     * Returns JSON for the piece page's card form (Inertia useHttp), not a
     * page visit: the browser needs the client secret to confirm the card.
     */
    public function store(CheckoutRequest $request, ArtPiece $artPiece, CheckoutService $checkout): JsonResponse
    {
        // Drafts don't exist publicly: 404, as on the public piece page.
        abort_unless($artPiece->status->isPublic(), 404);

        ['order' => $order, 'clientSecret' => $clientSecret, 'shipping' => $shipping] = $checkout->checkout(
            $artPiece,
            $request->validated('email'),
            $request->validated('shipping_address'),
        );

        return response()->json([
            'order_id' => $order->id,
            'subtotal_cents' => $order->subtotal_cents,
            'shipping_cents' => $order->shipping_cents,
            'total_cents' => $order->total_cents,
            'currency' => $order->currency,
            'shipping' => [
                'carrier' => $shipping->carrier,
                'service_level' => $shipping->serviceLevel,
                'estimated_days' => $shipping->estimatedDays,
            ],
            'client_secret' => $clientSecret,
        ], 201);
    }
}
