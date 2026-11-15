<?php

use App\Enums\ArtPieceStatus;
use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
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
        ->toThrow(DomainException::class, 'Cannot change art piece from sold to draft.');

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Sold);
});

test('a piece with an open order can\'t be changed with the status buttons', function (string $pieceStatus, string $orderStatus, string $to) {
    $this->actingAs(User::factory()->create());
    $piece = ArtPiece::factory()->create(['status' => $pieceStatus]);
    OrderItem::factory()->for(Order::factory()->create(['status' => $orderStatus]))->for($piece)->create();

    $this->patch(route('art-pieces.status.update', $piece), ['status' => $to])
        ->assertSessionHasErrors(['status' => 'This piece has an open order. Ship or refund it from Sales instead.']);

    expect($piece->fresh()->status->value)->toBe($pieceStatus);
})->with([
    // Relisting a held piece mid-checkout would let a second buyer collide with the first.
    'held, checkout in progress' => ['held', 'pending', 'published'],
    'sold, waiting to ship' => ['sold', 'paid', 'published'],
    'sold, shipped' => ['sold', 'fulfilled', 'published'],
]);

test('the piece page disables the status buttons while an order is open', function () {
    $this->actingAs(User::factory()->create());
    $piece = ArtPiece::factory()->create(['status' => 'sold']);
    OrderItem::factory()->for(Order::factory()->create(['status' => 'paid']))->for($piece)->create();

    $this->get(route('art-pieces.show', $piece))
        ->assertInertia(fn ($page) => $page
            ->where('artPiece.has_open_order', true)
            ->where('artPiece.allowed_statuses', []));
});
