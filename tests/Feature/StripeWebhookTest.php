<?php

use App\Enums\ArtPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.stripe.webhook_secret' => 'whsec_test']);

    // A checkout that's waiting for payment: held piece, pending order and payment.
    $this->piece = ArtPiece::factory()->create(['status' => 'held', 'held_until' => now()->addMinutes(20), 'price_cents' => 42_000]);
    $this->order = Order::factory()->create(['subtotal_cents' => 42_000, 'shipping_cents' => 0, 'total_cents' => 42_000]);
    OrderItem::factory()->for($this->order)->for($this->piece)->create();
    $this->payment = Payment::factory()->for($this->order)->create(['stripe_payment_intent_id' => 'pi_test_1']);

    /**
     * Send an event to the webhook, signed the way Stripe signs it:
     * "t=<time>,v1=<HMAC-SHA256 of '<time>.<body>'>".
     *
     * @param  array<string, mixed>  $event
     */
    $this->deliver = function (array $event, ?string $secret = 'whsec_test', ?int $signedAt = null): TestResponse {
        $body = json_encode($event);
        $timestamp = $signedAt ?? time();
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_STRIPE_SIGNATURE'] = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        }

        return $this->call('POST', route('stripe.webhook'), server: $headers, content: $body);
    };

    /**
     * @param  array<string, mixed>  $overrides  fields of the PaymentIntent
     * @return array<string, mixed>
     */
    $this->succeeded = fn (string $eventId = 'evt_1', array $overrides = []): array => [
        'id' => $eventId,
        'object' => 'event',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_test_1',
            'object' => 'payment_intent',
            'amount' => 42_000,
            'currency' => 'usd',
            ...$overrides,
        ]],
    ];

    $this->assertStillAwaitingPayment = function (): void {
        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Pending)
            ->and($this->order->fresh()->status)->toBe(OrderStatus::Pending)
            ->and($this->piece->fresh()->status)->toBe(ArtPieceStatus::Held);
    };
});

test('a succeeded payment marks the payment succeeded, the order paid and the piece sold', function () {
    $this->freezeSecond();

    ($this->deliver)(($this->succeeded)())->assertOk();

    expect($this->payment->fresh())
        ->status->toBe(PaymentStatus::Succeeded)
        ->stripe_event_id->toBe('evt_1');
    expect($this->order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->placed_at->equalTo(now())->toBeTrue();
    expect($this->piece->fresh())
        ->status->toBe(ArtPieceStatus::Sold)
        ->held_until->toBeNull();
});

test('a webhook signed with the wrong secret is rejected', function () {
    ($this->deliver)(($this->succeeded)(), secret: 'whsec_forged')->assertBadRequest();

    ($this->assertStillAwaitingPayment)();
    expect(DB::table('stripe_events_processed')->count())->toBe(0);
});

test('a webhook without a signature is rejected', function () {
    ($this->deliver)(($this->succeeded)(), secret: null)->assertBadRequest();

    ($this->assertStillAwaitingPayment)();
});

test('a correctly signed but old webhook is rejected, so a captured one can\'t be replayed later', function () {
    ($this->deliver)(($this->succeeded)(), signedAt: time() - 600)->assertBadRequest();

    ($this->assertStillAwaitingPayment)();
});

test('webhooks are refused when no webhook secret is configured', function () {
    config(['services.stripe.webhook_secret' => null]);

    ($this->deliver)(($this->succeeded)(), secret: '')->assertServerError();

    ($this->assertStillAwaitingPayment)();
});

test('a redelivered event is applied only once', function () {
    // An amount mismatch is logged every time it's applied, so the log
    // shows whether the second delivery was skipped.
    Log::spy();
    $mismatch = ($this->succeeded)('evt_1', ['amount' => 1]);

    ($this->deliver)($mismatch)->assertOk();
    ($this->deliver)($mismatch)->assertOk();

    Log::shouldHaveReceived('error')->once();
    expect(DB::table('stripe_events_processed')->count())->toBe(1);
});

test('a second success event with a new id cannot change an already paid order', function () {
    ($this->deliver)(($this->succeeded)('evt_1'))->assertOk();
    ($this->deliver)(($this->succeeded)('evt_2'))->assertOk();

    expect($this->payment->fresh())
        ->status->toBe(PaymentStatus::Succeeded)
        ->stripe_event_id->toBe('evt_1');
});

test('a payment that doesn\'t match the order is flagged instead of paid', function (array $overrides) {
    Log::spy();

    ($this->deliver)(($this->succeeded)('evt_1', $overrides))->assertOk();

    ($this->assertStillAwaitingPayment)();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'does not match the order'))->once();
})->with([
    'different amount' => [['amount' => 1]],
    'different currency' => [['currency' => 'eur']],
]);

test('an event for an unknown payment is acknowledged and ignored', function () {
    Log::spy();

    ($this->deliver)(($this->succeeded)('evt_1', ['id' => 'pi_unknown']))->assertOk();

    ($this->assertStillAwaitingPayment)();
    Log::shouldHaveReceived('warning')->once();
});

test('event types the app does not handle are acknowledged and recorded', function () {
    ($this->deliver)([
        'id' => 'evt_other',
        'object' => 'event',
        'type' => 'customer.created',
        'data' => ['object' => ['id' => 'cus_1', 'object' => 'customer']],
    ])->assertOk();

    ($this->assertStillAwaitingPayment)();
    expect(DB::table('stripe_events_processed')->where('stripe_event_id', 'evt_other')->exists())->toBeTrue();
});

test('if applying an event fails, nothing is recorded, so Stripe\'s retry applies it', function () {
    // The piece is in a state the state machine won't move to Sold.
    $this->piece->update(['status' => 'sold']);

    ($this->deliver)(($this->succeeded)('evt_1'))->assertServerError();

    expect(DB::table('stripe_events_processed')->count())->toBe(0)
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Pending);

    // Once the data is fixed, Stripe's retry of the same event goes through.
    $this->piece->update(['status' => 'held']);
    ($this->deliver)(($this->succeeded)('evt_1'))->assertOk();

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->piece->fresh()->status)->toBe(ArtPieceStatus::Sold);
});
