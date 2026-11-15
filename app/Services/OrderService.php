<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\ArtPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Mail\OrderUpdated;
use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * What staff do with a paid order: ship it or refund it. Each locks the
 * order row, so a double-click or two staff at once can't ship or refund
 * twice; the second attempt finds the new status and is refused.
 */
class OrderService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @throws DomainException if the order isn't paid
     */
    public function ship(Order $order, string $trackingNumber): void
    {
        DB::transaction(function () use ($order, $trackingNumber): void {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $order->transitionTo(OrderStatus::Fulfilled);
            $order->shipment()->updateOrCreate([], [
                'tracking_number' => $trackingNumber,
                'status' => ShipmentStatus::LabelCreated,
            ]);

            Mail::to($order->buyer_email)->queue(new OrderUpdated($order));
        });
    }

    /**
     * Refunds the full payment at Stripe. A piece that hasn't shipped goes
     * back on sale; a shipped one stays sold until it's returned.
     *
     * @throws DomainException if the order isn't paid, shipped or delivered
     */
    public function refund(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = Order::with(['payment', 'items.artPiece'])->lockForUpdate()->findOrFail($order->id);
            $hasShipped = $order->status !== OrderStatus::Paid;

            $order->transitionTo(OrderStatus::Refunded);
            $order->payment?->transitionTo(PaymentStatus::Refunded);

            foreach ($order->items as $item) {
                // A refunded order is closed: voiding its item frees the piece
                // for a new order (one live item per piece) and for the status
                // buttons, e.g. to relist a shipped piece once it's returned.
                $item->update(['voided_at' => now()]);

                if (! $hasShipped) {
                    $item->artPiece->transitionTo(ArtPieceStatus::Published);
                }
            }

            // Last, after every state check passed. If Stripe fails, all of
            // the above rolls back; if saving fails after Stripe refunded, the
            // retry reuses Stripe's refund (idempotency key) instead of paying twice.
            $this->gateway->refund((string) $order->payment?->stripe_payment_intent_id);

            Mail::to($order->buyer_email)->queue(new OrderUpdated($order));
        });
    }
}
