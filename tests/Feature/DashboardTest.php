<?php

use App\Models\ArtPiece;
use App\Models\ArtPieceImage;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard lists art pieces newest first with their images in position order', function () {
    $this->actingAs(User::factory()->create());
    $piece = ArtPiece::factory()->create(['name' => 'Charcoal Owl']);
    ArtPieceImage::factory()->for($piece)->create(['path' => 'art-pieces/second.jpg', 'position' => 1]);
    ArtPieceImage::factory()->for($piece)->create(['path' => 'art-pieces/first.jpg', 'position' => 0]);
    ArtPiece::factory()->create(['name' => 'No Images']);

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('artPieces.data', 2)
            ->where('artPieces.data.0.name', 'No Images')
            ->where('artPieces.data.0.images', [])
            ->where('artPieces.data.0.status', 'draft')
            ->where('artPieces.data.1.name', 'Charcoal Owl')
            ->where('artPieces.data.1.images', [
                'http://localhost/storage/art-pieces/first.jpg',
                'http://localhost/storage/art-pieces/second.jpg',
            ]));
});

test('dashboard paginates art pieces 24 per page', function () {
    $this->actingAs(User::factory()->create());
    ArtPiece::factory()->count(25)->create();

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('artPieces.data', 24)
            ->where('artPieces.last_page', 2));

    $this->get(route('dashboard', ['page' => 2]))
        ->assertInertia(fn ($page) => $page->has('artPieces.data', 1));
});

test('dashboard shares the image upload limits with the upload dialog', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('imageUploadLimits', [
                'max_images' => 19,
                'max_image_bytes' => 10 * 1024 * 1024,
                'max_total_bytes' => 80 * 1024 * 1024,
                'accepted_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            ]));
});
