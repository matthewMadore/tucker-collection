<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotalCents = fake()->numberBetween(1000, 100000);
        $shippingCents = 1500;

        return [
            'buyer_email' => fake()->safeEmail(),
            'status' => 'pending',
            'subtotal_cents' => $subtotalCents,
            'shipping_cents' => $shippingCents,
            'total_cents' => $subtotalCents + $shippingCents,
            'currency' => 'USD',
            'shipping_address' => [
                'name' => fake()->name(),
                'line1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'state' => fake()->randomElement(['CA', 'NY', 'TX', 'WA']),
                'postal_code' => fake()->postcode(),
                'country' => 'US',
            ],
        ];
    }
}
