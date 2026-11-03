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
    expect($piece->images->pluck('url', 'position')->all())->toBe([
        0 => 'https://example.com/a.jpg',
        1 => 'https://example.com/b.jpg',
    ]);
});

test('storing an art piece without a description saves an empty string', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/art-pieces', ['name' => 'Untitled', 'price' => '10'])
        ->assertSessionHasNoErrors();

    expect(ArtPiece::firstOrFail()->description)->toBe('');
});

test('invalid art piece input is rejected with validation errors', function (array $input, string $errorField) {
    $this->actingAs(User::factory()->create());

    $valid = ['name' => 'Charcoal Owl', 'price' => '49.99'];

    $this->from(route('dashboard'))
        ->post('/art-pieces', array_merge($valid, $input))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors($errorField);

    expect(ArtPiece::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'missing price' => [['price' => ''], 'price'],
    'non-numeric price' => [['price' => 'abc'], 'price'],
    'zero price' => [['price' => '0'], 'price'],
    'too many decimals' => [['price' => '1.999'], 'price'],
    'non-url image' => [['images' => ['not-a-url']], 'images.0'],
    'http image' => [['images' => ['http://example.com/a.jpg']], 'images.0'],
    'javascript image' => [['images' => ['javascript:alert(1)']], 'images.0'],
]);
