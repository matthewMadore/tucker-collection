<?php

namespace Database\Factories;

use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state. The snapshot copies the piece's
     * current name and price, as checkout does.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'art_piece_id' => ArtPiece::factory(),
            'name_snapshot' => fn (array $attributes) => ArtPiece::whereKey($attributes['art_piece_id'])->firstOrFail()->name,
            'price_cents_snapshot' => fn (array $attributes) => ArtPiece::whereKey($attributes['art_piece_id'])->firstOrFail()->price_cents,
        ];
    }
}
