<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\ArtPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\PieceUnavailableException;
use App\Models\ArtPiece;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    /**
     * How long a buyer has to pay before the piece is released.
     */
    public const int HOLD_MINUTES = 20;

    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Hold the piece, create a pending order and payment, and start the
     * payment with the gateway. All or nothing: if any step fails, the
     * transaction rolls back and the piece stays published.
     *
     * @param  array<string, string>  $shippingAddress
     * @return array{order: Order, clientSecret: string}
     *
     * @throws PieceUnavailableException when another buyer got there first
     */
    public function checkout(ArtPiece $piece, string $buyerEmail, array $shippingAddress): array
    {
        return DB::transaction(function () use ($piece, $buyerEmail, $shippingAddress): array {
            // The conditional UPDATE is the lock: there is no gap between
            // checking the status and changing it, so two buyers can't both
            // win. 0 rows means someone else held or bought it first.
            $held = ArtPiece::whereKey($piece->id)
                ->where('status', ArtPieceStatus::Published->value)
                ->update([
                    'status' => ArtPieceStatus::Held->value,
                    'held_until' => now()->addMinutes(self::HOLD_MINUTES),
                ]);

            if ($held === 0) {
                throw new PieceUnavailableException;
            }

            $piece->refresh();

            // Prices come from the database row, never from the request.
            $order = Order::create([
                'buyer_email' => $buyerEmail,
                'subtotal_cents' => $piece->price_cents,
                'shipping_cents' => 0,
                'total_cents' => $piece->price_cents,
                'currency' => $piece->currency,
                'shipping_address' => $shippingAddress,
            ]);

            $order->items()->create([
                'art_piece_id' => $piece->id,
                'name_snapshot' => $piece->name,
                'price_cents_snapshot' => $piece->price_cents,
            ]);

            $intent = $this->gateway->createIntent($order);

            $order->payment()->create([
                'stripe_payment_intent_id' => $intent->id,
                'amount_cents' => $order->total_cents,
                'currency' => $order->currency,
            ]);

            return ['order' => $order, 'clientSecret' => $intent->clientSecret];
        });
    }

    /**
     * Give up on an unpaid order whose hold expired: cancel the payment at
     * Stripe first, so the buyer can no longer pay for a piece that's back
     * on sale, then cancel the order and put the piece back on sale.
     *
     * If Stripe refuses to cancel (the payment already went through), this
     * throws and nothing changes; the webhook will mark the order paid.
     */
    public function releaseHold(Order $order): void
    {
        if ($order->payment !== null) {
            $this->gateway->cancelIntent($order->payment->stripe_payment_intent_id);
        }

        DB::transaction(function () use ($order): void {
            // Lock the order so a webhook marking it paid can't interleave.
            $order = Order::with(['payment', 'items.artPiece'])->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::Pending) {
                return;
            }

            $order->transitionTo(OrderStatus::Cancelled);
            $order->payment?->transitionTo(PaymentStatus::Failed);

            foreach ($order->items as $item) {
                $item->update(['voided_at' => now()]);
                $item->artPiece->transitionTo(ArtPieceStatus::Published, ['held_until' => null]);
            }
        });
    }
}
