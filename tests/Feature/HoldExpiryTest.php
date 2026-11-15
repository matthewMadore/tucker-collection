<?php

use App\Contracts\PaymentGateway;
use App\Enums\ArtPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    /**
     * A checkout waiting for payment, with its hold ending at $heldUntil.
     *
     * @return array{piece: ArtPiece, order: Order, item: OrderItem, payment: Payment}
     */
    $this->checkoutHeldUntil = function (?DateTimeInterface $heldUntil): array {
        $piece = ArtPiece::factory()->create(['status' => 'held', 'held_until' => $heldUntil]);
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->for($piece)->create();
        $payment = Payment::factory()->for($order)->create();

        return compact('piece', 'order', 'item', 'payment');
    };
});

test('an expired checkout is cancelled at Stripe and the piece goes back on sale', function () {
    ['piece' => $piece, 'order' => $order, 'item' => $item, 'payment' => $payment] = ($this->checkoutHeldUntil)(now()->subMinute());
    $this->mock(PaymentGateway::class)->shouldReceive('cancelIntent')->once()->with($payment->stripe_payment_intent_id);

    $this->artisan('app:release-expired-holds')
        ->expectsOutput('Released 1 expired hold(s).')
        ->assertSuccessful();

    expect($piece->fresh())->status->toBe(ArtPieceStatus::Published)->held_until->toBeNull()
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($item->fresh()->voided_at)->not->toBeNull();
});

test('holds that have not expired, and manual holds, are left alone', function (?string $heldUntil) {
    ['piece' => $piece, 'order' => $order] = ($this->checkoutHeldUntil)($heldUntil === null ? null : now()->modify($heldUntil));
    $this->mock(PaymentGateway::class)->shouldNotReceive('cancelIntent');

    $this->artisan('app:release-expired-holds')->expectsOutput('Released 0 expired hold(s).');

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Held)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'still within its 20 minutes' => ['+5 minutes'],
    'manual hold (no expiry)' => [null],
]);

test('if Stripe refuses to cancel because the payment went through, that hold is kept and the others are still released', function () {
    ['piece' => $paidPiece, 'order' => $paidOrder, 'payment' => $paidPayment] = ($this->checkoutHeldUntil)(now()->subMinute());
    ['piece' => $abandonedPiece] = ($this->checkoutHeldUntil)(now()->subMinute());
    $gateway = $this->mock(PaymentGateway::class);
    $gateway->shouldReceive('cancelIntent')->with($paidPayment->stripe_payment_intent_id)->andThrow(new RuntimeException('PaymentIntent already succeeded.'));
    $gateway->shouldReceive('cancelIntent')->once();

    $this->artisan('app:release-expired-holds')
        ->expectsOutput('Released 1 expired hold(s).')
        ->assertSuccessful();

    expect($paidPiece->fresh()->status)->toBe(ArtPieceStatus::Held)
        ->and($paidOrder->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($abandonedPiece->fresh()->status)->toBe(ArtPieceStatus::Published);
});

test('expired holds are released every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'app:release-expired-holds'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});
