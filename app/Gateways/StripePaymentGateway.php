<?php

namespace App\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * The only class that calls the Stripe API. Used whenever STRIPE_SECRET is
 * set (see AppServiceProvider); otherwise the fake gateway is used.
 */
class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function createIntent(Order $order): PaymentIntent
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount' => $order->total_cents,
            'currency' => strtolower($order->currency),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => ['order_id' => (string) $order->id],
            // Stripe emails the buyer a receipt when the payment succeeds.
            'receipt_email' => $order->buyer_email,
        ], [
            // A retried request (e.g. after a network timeout) returns the
            // same PaymentIntent instead of creating a second one.
            'idempotency_key' => "order-{$order->id}-payment-intent",
        ]);

        return new PaymentIntent($intent->id, (string) $intent->client_secret);
    }

    public function cancelIntent(string $intentId): void
    {
        try {
            $this->stripe->paymentIntents->cancel($intentId);
        } catch (InvalidRequestException $exception) {
            // Cancelling twice is fine; anything else (e.g. it already
            // succeeded) must stop the caller from releasing the hold.
            if ($this->stripe->paymentIntents->retrieve($intentId)->status !== 'canceled') {
                throw $exception;
            }
        }
    }
}
