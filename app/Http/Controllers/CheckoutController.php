<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\ArtPiece;
use App\Models\Payment;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    /**
     * Returns JSON for the piece page's card form (Inertia useHttp), not a
     * page visit: the browser needs the client secret to confirm the card.
     */
    public function store(CheckoutRequest $request, ArtPiece $artPiece, CheckoutService $checkout): JsonResponse
    {
        // Gallery-only mode takes no orders; drafts don't exist publicly.
        abort_unless(config('shop.enabled') && $artPiece->status->isPublic(), 404);

        ['order' => $order, 'clientSecret' => $clientSecret, 'shipping' => $shipping] = $checkout->checkout(
            $artPiece,
            $request->validated('email'),
            $request->validated('shipping_address'),
        );

        return response()->json([
            'subtotal_cents' => $order->subtotal_cents,
            'shipping_cents' => $order->shipping_cents,
            'total_cents' => $order->total_cents,
            'shipping' => [
                'service_level' => $shipping->serviceLevel,
                'estimated_days' => $shipping->estimatedDays,
            ],
            'client_secret' => $clientSecret,
        ], 201);
    }

    /**
     * Where Stripe sends the buyer after the card form. The query string only
     * says which payment this was; the order page shows our own status for
     * it, which only the webhook marks paid.
     */
    public function complete(Request $request): RedirectResponse
    {
        $payment = Payment::with('order')
            ->where('stripe_payment_intent_id', (string) $request->query('payment_intent'))
            ->firstOrFail();

        return redirect($payment->order->viewUrl());
    }
}
