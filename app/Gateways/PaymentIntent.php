<?php

namespace App\Gateways;

/**
 * What a gateway hands back: no Stripe SDK type crosses into the app.
 * The client secret goes to the buyer's browser for the card form and is
 * never stored.
 */
final readonly class PaymentIntent
{
    public function __construct(
        public string $id,
        public string $clientSecret,
    ) {}
}
