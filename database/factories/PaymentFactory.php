<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state. The amount matches the order total.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'stripe_payment_intent_id' => 'pi_test_'.Str::random(24),
            'status' => 'pending',
            'amount_cents' => fn (array $attributes) => Order::whereKey($attributes['order_id'])->firstOrFail()->total_cents,
            'currency' => 'USD',
        ];
    }
}
