<?php

namespace App\Enums;

use App\Concerns\HasStatusTransitions;

/**
 * Webhook-driven. Edges only move forward, so a late or duplicated Stripe
 * event (e.g. 'processing' after 'succeeded') can never regress a payment.
 * Failed and refunded are terminal: a retry is a new payment.
 */
enum PaymentStatus: string
{
    use HasStatusTransitions;

    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Disputed = 'disputed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Failed],
            self::Processing => [self::Succeeded, self::Failed],
            self::Succeeded => [self::Refunded, self::Disputed],
            self::Disputed => [self::Refunded, self::Succeeded],
            self::Failed, self::Refunded => [],
        };
    }
}
