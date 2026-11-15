<?php

namespace App\Contracts;

use App\Gateways\PaymentIntent;
use App\Models\Order;

/**
 * The app's only seam to Stripe. Checkout and webhooks depend on this
 * interface; only the gateway implementations know about Stripe itself.
 */
interface PaymentGateway
{
    /**
     * Start a payment for the order's total. The amount always comes from
     * the order (read from the database), never from the browser.
     */
    public function createIntent(Order $order): PaymentIntent;

    /**
     * Stop the payment from being completed (e.g. its hold expired).
     * Throws if it can't be cancelled, for example because it already
     * succeeded.
     */
    public function cancelIntent(string $intentId): void;

    /**
     * Refund a succeeded payment in full. Safe to call twice for the same
     * payment: the second call returns the first refund instead of making
     * another.
     */
    public function refund(string $intentId): void;
}
