<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Name and price are copied at purchase time, so later edits to the piece
 * don't change what the buyer paid for.
 *
 * @property ?CarbonImmutable $voided_at
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $fillable = ['order_id', 'art_piece_id', 'name_snapshot', 'price_cents_snapshot', 'voided_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<ArtPiece, $this>
     */
    public function artPiece(): BelongsTo
    {
        return $this->belongsTo(ArtPiece::class);
    }
}
