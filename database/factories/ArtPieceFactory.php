<?php

namespace Database\Factories;

use App\Models\ArtPiece;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtPiece>
 */
class ArtPieceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price_cents' => fake()->numberBetween(1000, 100000),
            'status' => 'draft',
        ];
    }
}
