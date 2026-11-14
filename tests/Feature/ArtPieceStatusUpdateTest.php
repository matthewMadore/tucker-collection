<?php

use App\Enums\ArtPieceStatus;
use App\Models\ArtPiece;
use App\Models\User;

test('guests cannot change a piece status', function () {
    $piece = ArtPiece::factory()->create(['status' => 'draft']);

    $this->patch(route('art-pieces.status.update', $piece), ['status' => 'published'])
        ->assertRedirect(route('login'));

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Draft);
});

test('publishing a piece updates its status and flashes a toast', function () {
    $this->actingAs(User::factory()->create());
    $piece = ArtPiece::factory()->create(['status' => 'draft']);

    $this->from(route('art-pieces.show', $piece))
        ->patch(route('art-pieces.status.update', $piece), ['status' => 'published'])
        ->assertRedirect(route('art-pieces.show', $piece))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Status changed to Published.',
        ]);

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Published);
});

test('an unknown status is rejected', function () {
    $this->actingAs(User::factory()->create());
    $piece = ArtPiece::factory()->create(['status' => 'draft']);

    $this->from(route('art-pieces.show', $piece))
        ->patch(route('art-pieces.status.update', $piece), ['status' => 'archived'])
        ->assertSessionHasErrors(['status' => 'The selected status is invalid.']);

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Draft);
});

test('a move the state machine forbids is rejected', function () {
    $this->actingAs(User::factory()->create());
    $piece = ArtPiece::factory()->create(['status' => 'sold']);

    $this->from(route('art-pieces.show', $piece))
        ->patch(route('art-pieces.status.update', $piece), ['status' => 'draft'])
        ->assertSessionHasErrors(['status' => "Can't change status from Sold to Draft."]);

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Sold);
});

test('the model refuses an illegal transition instead of saving it', function () {
    $piece = ArtPiece::factory()->create(['status' => 'sold']);

    expect(fn () => $piece->transitionTo(ArtPieceStatus::Draft))
        ->toThrow(DomainException::class, 'Cannot change an art piece from sold to draft.');

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Sold);
});
