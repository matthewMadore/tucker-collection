<?php

namespace App\Models;

use App\Concerns\TransitionsStatus;
use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A guest checkout: the buyer is identified by email, not a user account.
 *
 * @property OrderStatus $status
 * @property array<string, string> $shipping_address
 * @property ?CarbonImmutable $placed_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, TransitionsStatus;

    protected $fillable = [
        'buyer_email', 'status', 'subtotal_cents', 'shipping_cents', 'total_cents',
        'currency', 'shipping_address', 'placed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'shipping_address' => 'array',
            'placed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * @return HasOne<Shipment, $this>
     */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }
}
