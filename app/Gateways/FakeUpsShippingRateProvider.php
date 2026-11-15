<?php

namespace App\Gateways;

use App\Contracts\ShippingRateProvider;
use App\Models\ArtPiece;

/**
 * Stands in for the UPS Rating API until UPS credentials are configured.
 * Deterministic, so tests and demos get an explainable number: a base rate
 * plus $5 of declared-value insurance per full $1,000 of the piece's price.
 */
class FakeUpsShippingRateProvider implements ShippingRateProvider
{
    public const int BASE_RATE_CENTS = 4_500;

    public const int INSURANCE_CENTS_PER_THOUSAND_DOLLARS = 500;

    public function quote(ArtPiece $piece, array $shippingAddress): ShippingQuote
    {
        $insuranceCents = intdiv($piece->price_cents, 100_000) * self::INSURANCE_CENTS_PER_THOUSAND_DOLLARS;

        return new ShippingQuote(
            carrier: 'ups',
            serviceLevel: 'UPS Ground, signature required',
            rateCents: self::BASE_RATE_CENTS + $insuranceCents,
            estimatedDays: 5,
        );
    }
}
