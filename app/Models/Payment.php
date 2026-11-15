<?php

namespace App\Models;

use App\Concerns\TransitionsStatus;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Stripe PaymentIntent. A failed payment is terminal; a retry is a new row.
 *
 * @property PaymentStatus $status
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, TransitionsStatus;

    protected $fillable = ['order_id', 'stripe_payment_intent_id', 'stripe_event_id', 'status', 'amount_cents', 'currency'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
