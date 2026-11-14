<?php

namespace Database\Factories;

use App\Models\ArtPiece;
use App\Models\ArtPieceImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtPieceImage>
 */
class ArtPieceImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'art_piece_id' => ArtPiece::factory(),
            'path' => 'art-pieces/'.fake()->unique()->uuid().'.jpg',
            'position' => 0,
        ];
    }
}
