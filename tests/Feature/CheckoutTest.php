<?php

use App\Contracts\PaymentGateway;
use App\Enums\ArtPieceStatus;
use App\Models\ArtPiece;
use App\Models\Order;
use App\Services\CheckoutService;

beforeEach(function () {
    $this->payload = [
        'email' => 'buyer@example.com',
        'shipping_address' => [
            'name' => 'Ada Lovelace',
            'line1' => '1 Analytical Engine Way',
            'city' => 'Portland',
            'state' => 'OR',
            'postal_code' => '97201',
            'country' => 'US',
        ],
    ];
});

test('a guest can check out a published piece', function () {
    $this->freezeSecond();
    $piece = ArtPiece::factory()->create(['name' => 'Charcoal Owl', 'price_cents' => 42_000, 'status' => 'published']);

    $response = $this->postJson(route('checkout.store', $piece), $this->payload)
        ->assertCreated()
        ->assertJsonPath('total_cents', 42_000)
        ->assertJsonPath('currency', 'USD');

    $piece->refresh();
    expect($piece->status)->toBe(ArtPieceStatus::Held)
        ->and($piece->held_until->equalTo(now()->addMinutes(CheckoutService::HOLD_MINUTES)))->toBeTrue();

    $order = Order::with(['items', 'payment'])->findOrFail($response->json('order_id'));
    expect($order)
        ->buyer_email->toBe('buyer@example.com')
        ->status->value->toBe('pending')
        ->subtotal_cents->toBe(42_000)
        ->shipping_cents->toBe(0)
        ->total_cents->toBe(42_000)
        ->shipping_address->toEqual($this->payload['shipping_address']);
    expect($order->items->sole())
        ->art_piece_id->toBe($piece->id)
        ->name_snapshot->toBe('Charcoal Owl')
        ->price_cents_snapshot->toBe(42_000);
    expect($order->payment)
        ->status->value->toBe('pending')
        ->amount_cents->toBe(42_000);
    expect($response->json('client_secret'))->toStartWith($order->payment->stripe_payment_intent_id.'_secret_');
});

test('the price comes from the database, never from the request', function () {
    $piece = ArtPiece::factory()->create(['price_cents' => 42_000, 'status' => 'published']);

    $this->postJson(route('checkout.store', $piece), [
        ...$this->payload,
        'amount_cents' => 1,
        'total_cents' => 1,
        'price_cents' => 1,
    ])->assertCreated()->assertJsonPath('total_cents', 42_000);

    expect(Order::sole()->payment->amount_cents)->toBe(42_000);
});

test('a piece that is already held or sold cannot be checked out', function (string $status) {
    $piece = ArtPiece::factory()->create(['status' => $status]);

    $this->postJson(route('checkout.store', $piece), $this->payload)
        ->assertConflict()
        ->assertJsonPath('message', 'This piece is no longer available.');

    expect(Order::count())->toBe(0)
        ->and($piece->fresh()->status->value)->toBe($status);
})->with(['held', 'sold']);

test('a draft piece is not found at checkout', function () {
    $piece = ArtPiece::factory()->create(['status' => 'draft']);

    $this->postJson(route('checkout.store', $piece), $this->payload)->assertNotFound();

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Draft);
});

test('checkout requires a valid email and a full shipping address', function () {
    $piece = ArtPiece::factory()->create(['status' => 'published']);

    $this->postJson(route('checkout.store', $piece), [
        'email' => 'not-an-email',
        'shipping_address' => ['name' => 'Ada Lovelace'],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'email' => 'The email field must be a valid email address.',
        'shipping_address.line1' => 'The address field is required.',
        'shipping_address.city' => 'The city field is required.',
        'shipping_address.postal_code' => 'The ZIP / postal code field is required.',
        'shipping_address.state', 'shipping_address.country',
    ]);

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Published);
});

test('if the payment gateway fails, nothing is saved and the piece stays published', function () {
    $this->app->bind(PaymentGateway::class, fn () => new class implements PaymentGateway
    {
        public function createIntent(Order $order): never
        {
            throw new RuntimeException('Stripe is down.');
        }

        public function cancelIntent(string $intentId): void {}
    });
    $piece = ArtPiece::factory()->create(['status' => 'published']);

    $this->postJson(route('checkout.store', $piece), $this->payload)->assertServerError();

    expect(Order::count())->toBe(0)
        ->and($piece->fresh())
        ->status->toBe(ArtPieceStatus::Published)
        ->held_until->toBeNull();
});

test('checkout is rate limited per IP', function () {
    $piece = ArtPiece::factory()->create(['status' => 'sold']);

    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('checkout.store', $piece), $this->payload)->assertConflict();
    }

    $this->postJson(route('checkout.store', $piece), $this->payload)->assertTooManyRequests();
});
