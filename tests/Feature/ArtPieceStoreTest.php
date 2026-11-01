<?php

use App\Models\ArtPiece;
use App\Models\User;

test('storing an art piece saves it with images and flashes a success toast', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->from(route('dashboard'))->post('/art-pieces', [
        'name' => 'Charcoal Owl',
        'price' => '49.99',
        'description' => 'Study of a barn owl.',
        'images' => ['https://example.com/a.jpg', '', 'https://example.com/b.jpg'],
    ]);

    $response->assertRedirect(route('dashboard'));
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Art piece added.',
    ]);

    $piece = ArtPiece::with('images')->firstOrFail();
    expect($piece->price_cents)->toBe(4999);
    expect($piece->images)->toHaveCount(2);
});
