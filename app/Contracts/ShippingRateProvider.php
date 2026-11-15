<?php

namespace App\Contracts;

use App\Gateways\ShippingQuote;
use App\Models\ArtPiece;

/**
 * The app's only seam to the shipping carrier (UPS). Checkout asks it for a
 * rate on the server, so the buyer can't choose their own shipping price.
 */
interface ShippingRateProvider
{
    /**
     * @param  array<string, string>  $shippingAddress
     */
    public function quote(ArtPiece $piece, array $shippingAddress): ShippingQuote;
}
