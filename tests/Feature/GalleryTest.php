<?php

use App\Models\ArtPiece;
use App\Models\ArtPieceImage;

test('the public gallery lists published, held and sold pieces newest first, but not drafts', function () {
    $published = ArtPiece::factory()->create(['name' => 'Published', 'status' => 'published']);
    ArtPiece::factory()->create(['name' => 'Draft', 'status' => 'draft']);
    $held = ArtPiece::factory()->create(['name' => 'Held', 'status' => 'held']);
    $sold = ArtPiece::factory()->create(['name' => 'Sold', 'status' => 'sold']);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gallery/Index')
            ->has('artPieces.data', 3)
            ->where('artPieces.data.0.id', $sold->id)
            ->where('artPieces.data.0.status_label', 'Sold')
            ->where('artPieces.data.1.id', $held->id)
            ->where('artPieces.data.1.status_label', 'On hold')
            ->where('artPieces.data.2.id', $published->id));
});

test('a gallery card uses the first image by position as its cover', function () {
    $piece = ArtPiece::factory()->create(['status' => 'published']);
    ArtPieceImage::factory()->for($piece)->create(['path' => 'art-pieces/second.jpg', 'position' => 1]);
    ArtPieceImage::factory()->for($piece)->create(['path' => 'art-pieces/first.jpg', 'position' => 0]);
    $withoutImages = ArtPiece::factory()->create(['status' => 'published']);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('artPieces.data.0.id', $withoutImages->id)
            ->where('artPieces.data.0.cover_image_url', null)
            ->where('artPieces.data.1.cover_image_url', 'http://localhost/storage/art-pieces/first.jpg'));
});

test('guests can view a published piece with its images in position order', function () {
    $piece = ArtPiece::factory()->create([
        'name' => 'Charcoal Owl',
        'description' => 'Study of a barn owl.',
        'price_cents' => 4999,
        'status' => 'published',
    ]);
    $second = ArtPieceImage::factory()->for($piece)->create(['path' => 'art-pieces/second.jpg', 'position' => 1]);
    $first = ArtPieceImage::factory()->for($piece)->create(['path' => 'art-pieces/first.jpg', 'position' => 0]);

    $this->get(route('gallery.show', $piece))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gallery/Show')
            ->where('artPiece.name', 'Charcoal Owl')
            ->where('artPiece.description', 'Study of a barn owl.')
            ->where('artPiece.price_cents', 4999)
            ->where('artPiece.status_label', 'Published')
            ->where('artPiece.images', [
                ['id' => $first->id, 'url' => 'http://localhost/storage/art-pieces/first.jpg'],
                ['id' => $second->id, 'url' => 'http://localhost/storage/art-pieces/second.jpg'],
            ])
            ->where('holdMinutes', 20));
});

test('a draft piece is not found on the public site', function () {
    $draft = ArtPiece::factory()->create(['status' => 'draft']);

    $this->get(route('gallery.show', $draft))->assertNotFound();
});

test('the piece page gives the card form the Stripe publishable key, and only that key', function () {
    config(['services.stripe.key' => 'pk_test_123', 'services.stripe.secret' => 'sk_test_secret']);
    $piece = ArtPiece::factory()->create(['status' => 'published']);

    $response = $this->get(route('gallery.show', $piece))
        ->assertInertia(fn ($page) => $page->where('stripeKey', 'pk_test_123'));

    expect($response->getContent())->not->toContain('sk_test_secret');
});

test('in gallery-only mode, prices are hidden and the site says nothing is for sale', function () {
    config(['shop.enabled' => false]);
    $piece = ArtPiece::factory()->create(['status' => 'published', 'price_cents' => 4999]);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('shopEnabled', false)
            ->where('artPieces.data.0.price_cents', null));

    $response = $this->get(route('gallery.show', $piece))
        ->assertInertia(fn ($page) => $page->where('artPiece.price_cents', null));

    expect($response->getContent())->not->toContain('4999');
});
