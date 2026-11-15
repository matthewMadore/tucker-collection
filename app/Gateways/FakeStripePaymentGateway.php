<?php

namespace App\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Used when no Stripe keys are configured (local development and tests).
 */
class FakeStripePaymentGateway implements PaymentGateway
{
    public function createIntent(Order $order): PaymentIntent
    {
        $id = 'pi_fake_'.Str::random(24);

        return new PaymentIntent($id, "{$id}_secret_".Str::random(16));
    }
}
