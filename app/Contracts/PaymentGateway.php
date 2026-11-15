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
}
