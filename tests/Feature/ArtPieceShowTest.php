<?php

use App\Models\ArtPiece;
use App\Models\ArtPieceImage;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $piece = ArtPiece::factory()->create();

    $this->get(route('art-pieces.show', $piece))
        ->assertRedirect(route('login'));
});

test('the art piece page shows its details and image URLs in position order', function () {
    $this->actingAs(User::factory()->create());
    $piece = ArtPiece::factory()->create([
        'name' => 'Charcoal Owl',
        'description' => 'Study of a barn owl.',
        'price_cents' => 4999,
    ]);
    $second = ArtPieceImage::factory()->for($piece)->create(['path' => 'art-pieces/second.jpg', 'position' => 1]);
    $first = ArtPieceImage::factory()->for($piece)->create(['path' => 'art-pieces/first.jpg', 'position' => 0]);

    $this->get(route('art-pieces.show', $piece))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('art-pieces/Show')
            ->where('artPiece.id', $piece->id)
            ->where('artPiece.name', 'Charcoal Owl')
            ->where('artPiece.description', 'Study of a barn owl.')
            ->where('artPiece.price_cents', 4999)
            ->where('artPiece.status', 'draft')
            ->where('artPiece.images', [
                ['id' => $first->id, 'url' => 'http://localhost/storage/art-pieces/first.jpg'],
                ['id' => $second->id, 'url' => 'http://localhost/storage/art-pieces/second.jpg'],
            ]));
});

test('an art piece without images has an empty image list', function () {
    $this->actingAs(User::factory()->create());
    $piece = ArtPiece::factory()->create();

    $this->get(route('art-pieces.show', $piece))
        ->assertInertia(fn ($page) => $page->where('artPiece.images', []));
});

test('a missing art piece returns 404', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/art-pieces/999999')->assertNotFound();
});
