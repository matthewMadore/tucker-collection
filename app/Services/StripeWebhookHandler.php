<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\ArtPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\OrderConfirmed;
use App\Mail\OrderReceived;
use App\Mail\OrderUpdated;
use App\Mail\PaymentDisputed;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
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
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderService $orders,
    ) {}

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
                'charge.refunded' => $this->chargeRefunded($eventId, $object),
                'charge.dispute.created' => $this->disputeCreated($eventId, $object),
                'charge.dispute.closed' => $this->disputeClosed($eventId, $object),
                default => null,
            };
        });
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function paymentSucceeded(string $eventId, array $intent): void
    {
        $payment = $this->findPayment($eventId, $intent['id'] ?? null);

        if ($payment === null) {
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
        // back on sale: refund them instead of selling it. If the refund
        // fails, the webhook fails and Stripe's retry tries again.
        if ($order->status !== OrderStatus::Pending) {
            $this->gateway->refund($payment->stripe_payment_intent_id);

            Log::warning('Stripe payment succeeded for an order that is no longer pending; refunded it.', [
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

    /**
     * A refund made outside the app (in Stripe's dashboard). The app's own
     * refunds also produce this event; their order is already refunded, so
     * it's skipped, as is the late-payment refund of a cancelled order.
     *
     * @param  array<string, mixed>  $charge
     */
    private function chargeRefunded(string $eventId, array $charge): void
    {
        if (($charge['refunded'] ?? false) !== true) {
            Log::warning('Partial refund made in Stripe; the app only records full refunds.', [
                'event' => $eventId,
                'intent' => $charge['payment_intent'] ?? null,
                'amount_refunded' => $charge['amount_refunded'] ?? null,
            ]);

            return;
        }

        $order = $this->lockOrderFor($eventId, $charge['payment_intent'] ?? null);

        if ($order === null || ! $order->status->canTransitionTo(OrderStatus::Refunded)) {
            return;
        }

        $this->orders->recordRefund($order);
        Mail::to($order->buyer_email)->queue(new OrderUpdated($order));
    }

    /**
     * The buyer asked their bank to reverse the charge. Stripe has already
     * taken the money back while the dispute is open.
     *
     * @param  array<string, mixed>  $dispute
     */
    private function disputeCreated(string $eventId, array $dispute): void
    {
        $order = $this->lockOrderFor($eventId, $dispute['payment_intent'] ?? null);

        if ($order === null) {
            return;
        }

        if ($order->payment?->status->canTransitionTo(PaymentStatus::Disputed) !== true) {
            Log::warning('Stripe dispute for a payment that is not succeeded.', ['event' => $eventId, 'order_id' => $order->id]);

            return;
        }

        $order->payment->transitionTo(PaymentStatus::Disputed);

        $dueBy = $dispute['evidence_details']['due_by'] ?? null;
        $this->emailOwners($order, $dispute, 'open', is_int($dueBy) ? CarbonImmutable::createFromTimestamp($dueBy) : null);
    }

    /**
     * Lost: the money stays with the buyer, so the order is recorded as
     * refunded. Won (or an inquiry closed without a chargeback): the
     * payment is ours again.
     *
     * @param  array<string, mixed>  $dispute
     */
    private function disputeClosed(string $eventId, array $dispute): void
    {
        $order = $this->lockOrderFor($eventId, $dispute['payment_intent'] ?? null);

        if ($order === null || $order->payment?->status !== PaymentStatus::Disputed) {
            return;
        }

        $lost = ($dispute['status'] ?? null) === 'lost';

        if ($lost) {
            $this->orders->recordRefund($order);
        } else {
            $order->payment->transitionTo(PaymentStatus::Succeeded);
        }

        $this->emailOwners($order, $dispute, outcome: $lost ? 'lost' : 'won');
    }

    /**
     * @param  array<string, mixed>  $dispute
     * @param  'open'|'won'|'lost'  $outcome
     */
    private function emailOwners(Order $order, array $dispute, string $outcome, ?CarbonImmutable $respondBy = null): void
    {
        Mail::to(User::all())->queue(new PaymentDisputed(
            $order,
            (string) ($dispute['id'] ?? ''),
            $outcome,
            (string) ($dispute['reason'] ?? ''),
            $respondBy,
        ));
    }

    private function findPayment(string $eventId, mixed $intentId): ?Payment
    {
        $payment = Payment::query()->where('stripe_payment_intent_id', $intentId)->first();

        if ($payment === null) {
            Log::warning('Stripe webhook for an unknown payment intent.', ['event' => $eventId, 'intent' => $intentId]);
        }

        return $payment;
    }

    /**
     * Locked like staff actions lock it, so a dashboard refund or dispute
     * can't interleave with a refund or shipment from the Sales page.
     */
    private function lockOrderFor(string $eventId, mixed $intentId): ?Order
    {
        $payment = $this->findPayment($eventId, $intentId);

        return $payment === null
            ? null
            : Order::with(['payment', 'items.artPiece', 'shipment'])->lockForUpdate()->findOrFail($payment->order_id);
    }
}
