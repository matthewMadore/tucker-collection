<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\ArtPiece;
use App\Models\Payment;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
     * Where Stripe sends the buyer after the card form. The order status
     * shown comes from our database, which only the webhook marks paid; the
     * query string is just Stripe telling us which payment this was.
     */
    public function complete(Request $request): Response
    {
        $payment = Payment::with('order.items')
            ->where('stripe_payment_intent_id', (string) $request->query('payment_intent'))
            ->firstOrFail();

        return Inertia::render('gallery/CheckoutComplete', [
            'order' => [
                'status' => $payment->order->status->value,
                'piece_name' => $payment->order->items->first()?->name_snapshot,
                'total_cents' => $payment->order->total_cents,
                'buyer_email' => $payment->order->buyer_email,
            ],
        ]);
    }
}
