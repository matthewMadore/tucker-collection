<?php

namespace App\Services;

use App\Enums\ArtPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\OrderConfirmed;
use App\Mail\OrderReceived;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Applies verified Stripe events. The webhook, not the buyer's browser, is
 * the source of truth for payment: it's signed, retried, and arrives even
 * if the buyer closes the tab.
 */
class StripeWebhookHandler
{
    /**
     * Recording the event and applying it happen in one transaction: if
     * applying fails, the event isn't recorded either, so Stripe's retry
     * runs it again. (Fable recorded first, so a failed attempt made every
     * retry a silent no-op.)
     *
     * @param  array<string, mixed>  $object  the event's data.object
     */
    public function handle(string $eventId, string $type, array $object): void
    {
        DB::transaction(function () use ($eventId, $type, $object): void {
            $isFirstDelivery = DB::table('stripe_events_processed')->insertOrIgnore([
                'stripe_event_id' => $eventId,
                'type' => $type,
            ]) === 1;

            if (! $isFirstDelivery) {
                return;
            }

            match ($type) {
                'payment_intent.succeeded' => $this->paymentSucceeded($eventId, $object),
                default => null,
            };
        });
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function paymentSucceeded(string $eventId, array $intent): void
    {
        $payment = Payment::query()
            ->where('stripe_payment_intent_id', $intent['id'] ?? null)
            ->first();

        if ($payment === null) {
            Log::warning('Stripe webhook for an unknown payment intent.', ['event' => $eventId, 'intent' => $intent['id'] ?? null]);

            return;
        }

        // Already paid through an earlier event (Stripe can send the same
        // outcome under a new event id): never move it backwards.
        if ($payment->status === PaymentStatus::Succeeded) {
            return;
        }

        // Lock the order so the hold-expiry job can't cancel it mid-update.
        $order = Order::with('items.artPiece')->lockForUpdate()->findOrFail($payment->order_id);

        // The hold expired and the order was cancelled before the payment
        // landed. Expiry cancels the payment at Stripe first, so this should
        // be rare, but if it happens the buyer was charged for a piece that's
        // back on sale: flag it for a refund instead of selling it.
        if ($order->status !== OrderStatus::Pending) {
            Log::critical('Stripe payment succeeded for an order that is no longer pending; refund it.', [
                'event' => $eventId,
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'order_status' => $order->status->value,
            ]);

            return;
        }

        // The charge must match what we asked for, or the order is flagged
        // for review instead of paid.
        $amountMatches = ($intent['amount'] ?? null) === $payment->amount_cents;
        $currencyMatches = strtoupper((string) ($intent['currency'] ?? '')) === $payment->currency;

        if (! $amountMatches || ! $currencyMatches) {
            Log::error('Stripe payment does not match the order; not marking it paid.', [
                'event' => $eventId,
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'expected' => [$payment->amount_cents, $payment->currency],
                'received' => [$intent['amount'] ?? null, $intent['currency'] ?? null],
            ]);

            return;
        }

        if ($payment->status === PaymentStatus::Pending) {
            $payment->transitionTo(PaymentStatus::Processing, ['stripe_event_id' => $eventId]);
        }
        $payment->transitionTo(PaymentStatus::Succeeded, ['stripe_event_id' => $eventId]);

        $order->transitionTo(OrderStatus::Paid, ['placed_at' => now()]);

        foreach ($order->items as $item) {
            $item->artPiece->transitionTo(ArtPieceStatus::Sold, ['held_until' => null]);
        }

        // Both are queued to send after this transaction commits.
        Mail::to($order->buyer_email)->queue(new OrderConfirmed($order));
        Mail::to(User::all())->queue(new OrderReceived($order));
    }
}
