<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ShipmentStatus $status
 * @property ?CarbonImmutable $last_carrier_sync_at
 */
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id', 'carrier', 'service_level', 'tracking_number', 'status',
        'quoted_rate_cents', 'last_carrier_sync_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'last_carrier_sync_at' => 'datetime',
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
