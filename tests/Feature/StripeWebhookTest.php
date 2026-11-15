<?php

use App\Contracts\PaymentGateway;
use App\Enums\ArtPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\OrderConfirmed;
use App\Mail\OrderReceived;
use App\Mail\OrderUpdated;
use App\Mail\PaymentDisputed;
use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.stripe.webhook_secret' => 'whsec_test']);
    Mail::fake();

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
        Mail::assertNothingQueued();
    };
});

test('a succeeded payment marks the payment succeeded, the order paid and the piece sold', function () {
    $this->freezeSecond();
    $owners = User::factory()->count(2)->create();

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

    Mail::assertQueued(OrderConfirmed::class, fn (OrderConfirmed $mail) => $mail->hasTo($this->order->buyer_email) && $mail->order->is($this->order));
    Mail::assertQueued(OrderReceived::class, fn (OrderReceived $mail) => $mail->hasTo($owners[0]->email) && $mail->hasTo($owners[1]->email));
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
    // The buyer and owners are emailed once, not once per event.
    Mail::assertQueuedCount(2);
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
    Mail::assertQueued(OrderConfirmed::class, 1);
});

test('if the late-payment refund fails, the webhook fails so Stripe retries it', function () {
    $this->order->update(['status' => 'cancelled']);
    $this->payment->update(['status' => 'failed']);
    $this->mock(PaymentGateway::class)->shouldReceive('refund')->andThrow(new RuntimeException('Stripe is down.'));

    ($this->deliver)(($this->succeeded)())->assertServerError();

    expect(DB::table('stripe_events_processed')->count())->toBe(0);
});

test('a payment that lands after its order was cancelled is refunded, not sold', function () {
    Log::spy();
    $this->mock(PaymentGateway::class)->shouldReceive('refund')->once()->with('pi_test_1');
    // The hold expired and was released before the payment landed.
    $this->order->update(['status' => 'cancelled']);
    $this->payment->update(['status' => 'failed']);
    $this->piece->update(['status' => 'published', 'held_until' => null]);

    ($this->deliver)(($this->succeeded)())->assertOk();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($this->piece->fresh()->status)->toBe(ArtPieceStatus::Published);
    Mail::assertNothingQueued();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'refunded it'))->once();
});

describe('refunds and disputes made outside the app', function () {
    beforeEach(function () {
        // Paid through the real webhook first, as in production.
        ($this->deliver)(($this->succeeded)('evt_paid'))->assertOk();
        Mail::fake();

        /** @param array<string, mixed> $object */
        $this->event = fn (string $id, string $type, array $object): array => [
            'id' => $id, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object],
        ];
        $this->charge = fn (array $overrides = []): array => [
            'id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_test_1',
            'amount' => 42_000, 'amount_refunded' => 42_000, 'refunded' => true, ...$overrides,
        ];
        $this->dispute = fn (string $status, array $overrides = []): array => [
            'id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_test_1', 'charge' => 'ch_1',
            'amount' => 42_000, 'reason' => 'product_not_received', 'status' => $status,
            'evidence_details' => ['due_by' => 1_800_000_000], ...$overrides,
        ];
    });

    test('a full refund made in Stripe\'s dashboard is recorded, relists the piece and emails the buyer', function () {
        $this->mock(PaymentGateway::class)->shouldNotReceive('refund');

        ($this->deliver)(($this->event)('evt_r', 'charge.refunded', ($this->charge)()))->assertOk();

        expect($this->order->fresh()->status)->toBe(OrderStatus::Refunded)
            ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Refunded)
            ->and($this->piece->fresh()->status)->toBe(ArtPieceStatus::Published);
        Mail::assertQueued(OrderUpdated::class, fn (OrderUpdated $mail) => $mail->hasTo($this->order->buyer_email) && $mail->status === OrderStatus::Refunded);
    });

    test('the refund event for a refund the app made changes nothing and emails no one', function () {
        app(OrderService::class)->refund($this->order);
        Mail::fake();

        ($this->deliver)(($this->event)('evt_r', 'charge.refunded', ($this->charge)()))->assertOk();

        expect($this->order->fresh()->status)->toBe(OrderStatus::Refunded);
        Mail::assertNothingQueued();
    });

    test('a partial refund in Stripe is logged, not recorded as a full refund', function () {
        Log::spy();

        ($this->deliver)(($this->event)('evt_r', 'charge.refunded', ($this->charge)(['refunded' => false, 'amount_refunded' => 5_000])))->assertOk();

        expect($this->order->fresh()->status)->toBe(OrderStatus::Paid);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'Partial refund'))->once();
        Mail::assertNothingQueued();
    });

    test('a dispute marks the payment disputed and emails the owners the deadline', function () {
        $owner = User::factory()->create();

        ($this->deliver)(($this->event)('evt_d', 'charge.dispute.created', ($this->dispute)('needs_response')))->assertOk();

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Disputed)
            ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid);
        Mail::assertQueued(PaymentDisputed::class, fn (PaymentDisputed $mail) => $mail->hasTo($owner->email)
            && $mail->outcome === 'open'
            && $mail->reason === 'product_not_received'
            && $mail->respondBy?->timestamp === 1_800_000_000);
    });

    test('a dispute on a payment that was already refunded is logged and ignored', function () {
        app(OrderService::class)->refund($this->order);
        Mail::fake();
        Log::spy();

        ($this->deliver)(($this->event)('evt_d', 'charge.dispute.created', ($this->dispute)('needs_response')))->assertOk();

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Refunded);
        Log::shouldHaveReceived('warning')->once();
        Mail::assertNothingQueued();
    });

    test('a won dispute makes the payment succeeded again', function (string $closedStatus) {
        ($this->deliver)(($this->event)('evt_d', 'charge.dispute.created', ($this->dispute)('needs_response')))->assertOk();
        Mail::fake();

        ($this->deliver)(($this->event)('evt_c', 'charge.dispute.closed', ($this->dispute)($closedStatus)))->assertOk();

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid);
        Mail::assertQueued(PaymentDisputed::class, fn (PaymentDisputed $mail) => $mail->outcome === 'won');
    })->with(['won', 'inquiry closed without a chargeback' => 'warning_closed']);

    test('a lost dispute records the order as refunded without refunding again', function () {
        $this->mock(PaymentGateway::class)->shouldNotReceive('refund');
        ($this->deliver)(($this->event)('evt_d', 'charge.dispute.created', ($this->dispute)('needs_response')))->assertOk();
        Mail::fake();

        ($this->deliver)(($this->event)('evt_c', 'charge.dispute.closed', ($this->dispute)('lost')))->assertOk();

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Refunded)
            ->and($this->order->fresh()->status)->toBe(OrderStatus::Refunded)
            ->and($this->piece->fresh()->status)->toBe(ArtPieceStatus::Published);
        Mail::assertQueued(PaymentDisputed::class, fn (PaymentDisputed $mail) => $mail->outcome === 'lost');
        Mail::assertNotQueued(OrderUpdated::class);
    });

    test('a dispute closing for a payment that isn\'t disputed is ignored', function () {
        ($this->deliver)(($this->event)('evt_c', 'charge.dispute.closed', ($this->dispute)('lost')))->assertOk();

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
            ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid);
        Mail::assertNothingQueued();
    });
});
