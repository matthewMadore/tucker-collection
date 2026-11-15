<?php

namespace App\Gateways;

final readonly class ShippingQuote
{
    public function __construct(
        public string $carrier,
        public string $serviceLevel,
        public int $rateCents,
        public int $estimatedDays,
    ) {}
}
