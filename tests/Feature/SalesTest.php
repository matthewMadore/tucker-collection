<?php

use App\Contracts\PaymentGateway;
use App\Enums\ArtPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Mail\OrderUpdated;
use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    /**
     * A sale as the webhook leaves it: piece sold, order and payment paid.
     */
    $this->sale = function (string $status = 'paid', array $order = []): Order {
        $piece = ArtPiece::factory()->create(['status' => 'sold']);
        $sale = Order::factory()->create(['status' => $status, 'placed_at' => now(), ...$order]);
        OrderItem::factory()->for($sale)->for($piece)->create();
        Payment::factory()->for($sale)->create(['status' => $status === 'refunded' ? 'refunded' : 'succeeded']);
        Shipment::factory()->for($sale)->create(['service_level' => 'UPS Ground']);

        return $sale;
    };
});

test('guests cannot see sales, ship or refund', function () {
    $sale = ($this->sale)();

    $this->get(route('sales.index'))->assertRedirect(route('login'));
    $this->post(route('sales.ship', $sale), ['tracking_number' => '1Z999'])->assertRedirect(route('login'));
    $this->post(route('sales.refund', $sale))->assertRedirect(route('login'));

    expect($sale->fresh()->status)->toBe(OrderStatus::Paid);
});

test('the sales page lists paid orders newest first, with what can be done to each', function () {
    $this->actingAs(User::factory()->create());
    $shipped = ($this->sale)('fulfilled', ['placed_at' => now()->subDay()]);
    $toShip = ($this->sale)('paid');
    Order::factory()->create(['status' => 'pending']);
    Order::factory()->create(['status' => 'cancelled']);

    $this->get(route('sales.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/Index')
            ->has('orders.data', 2)
            ->where('orders.data.0.id', $toShip->id)
            ->where('orders.data.0.can_ship', true)
            ->where('orders.data.0.can_refund', true)
            ->where('orders.data.0.service_level', 'UPS Ground')
            ->where('orders.data.1.id', $shipped->id)
            ->where('orders.data.1.can_ship', false)
            ->where('orders.data.1.can_refund', true));
});

test('marking an order shipped saves the tracking number and emails the buyer', function () {
    $this->actingAs(User::factory()->create());
    $sale = ($this->sale)();

    $this->from(route('sales.index'))
        ->post(route('sales.ship', $sale), ['tracking_number' => '1Z999AA10123456784'])
        ->assertRedirect(route('sales.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($sale->fresh()->status)->toBe(OrderStatus::Fulfilled)
        ->and($sale->shipment->fresh())
        ->tracking_number->toBe('1Z999AA10123456784')
        ->status->toBe(ShipmentStatus::LabelCreated);
    Mail::assertQueued(OrderUpdated::class, fn (OrderUpdated $mail) => $mail->hasTo($sale->buyer_email) && $mail->status === OrderStatus::Fulfilled);
});

test('the tracking number is validated', function (mixed $trackingNumber) {
    $this->actingAs(User::factory()->create());
    $sale = ($this->sale)();

    $this->post(route('sales.ship', $sale), ['tracking_number' => $trackingNumber])
        ->assertSessionHasErrors('tracking_number');

    expect($sale->fresh()->status)->toBe(OrderStatus::Paid);
})->with([
    'missing' => [''],
    'not letters and digits' => ['1Z 999<script>'],
    'too long' => [str_repeat('1', 36)],
]);

test('an order that isn\'t paid can\'t be shipped', function (string $status) {
    $this->actingAs(User::factory()->create());
    $sale = ($this->sale)($status);

    $this->post(route('sales.ship', $sale), ['tracking_number' => '1Z999'])
        ->assertInertiaFlash('toast.type', 'error');

    expect($sale->fresh()->status->value)->toBe($status);
    Mail::assertNothingQueued();
})->with(['fulfilled', 'refunded']);

test('refunding an unshipped order refunds at Stripe and puts the piece back on sale', function () {
    $this->actingAs(User::factory()->create());
    $sale = ($this->sale)();
    $this->mock(PaymentGateway::class)->shouldReceive('refund')->once()->with($sale->payment->stripe_payment_intent_id);

    $this->post(route('sales.refund', $sale))->assertInertiaFlash('toast.type', 'success');

    expect($sale->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($sale->payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($sale->items->first()->fresh()->voided_at)->not->toBeNull()
        ->and($sale->items->first()->artPiece->fresh()->status)->toBe(ArtPieceStatus::Published);
    Mail::assertQueued(OrderUpdated::class, fn (OrderUpdated $mail) => $mail->hasTo($sale->buyer_email) && $mail->status === OrderStatus::Refunded);
});

test('a piece put back on sale by a refund can be bought again', function () {
    $this->actingAs(User::factory()->create());
    $sale = ($this->sale)();
    $this->post(route('sales.refund', $sale));
    auth()->logout();

    $this->postJson(route('checkout.store', $sale->items->first()->art_piece_id), [
        'email' => 'second@example.com',
        'shipping_address' => ['name' => 'Ada', 'line1' => '1 Main St', 'city' => 'Portland', 'state' => 'OR', 'postal_code' => '97201', 'country' => 'US'],
    ])->assertCreated();
});

test('after a shipped order is refunded and the piece comes back, staff can relist it', function () {
    $this->actingAs(User::factory()->create());
    $sale = ($this->sale)('fulfilled');
    $this->post(route('sales.refund', $sale));
    $piece = $sale->items->first()->artPiece;

    $this->patch(route('art-pieces.status.update', $piece), ['status' => 'published'])->assertSessionHasNoErrors();

    expect($piece->fresh()->status)->toBe(ArtPieceStatus::Published);
});

test('refunding a shipped order keeps the piece sold', function () {
    $this->actingAs(User::factory()->create());
    $sale = ($this->sale)('fulfilled');

    $this->post(route('sales.refund', $sale))->assertInertiaFlash('toast.type', 'success');

    expect($sale->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($sale->items->first()->fresh()->voided_at)->not->toBeNull()
        ->and($sale->items->first()->artPiece->fresh()->status)->toBe(ArtPieceStatus::Sold);
});

test('an order can\'t be refunded twice or before it\'s paid, and Stripe isn\'t called', function (string $status) {
    $this->actingAs(User::factory()->create());
    $sale = ($this->sale)($status);
    $this->mock(PaymentGateway::class)->shouldNotReceive('refund');

    $this->post(route('sales.refund', $sale))->assertInertiaFlash('toast.type', 'error');

    expect($sale->fresh()->status->value)->toBe($status);
    Mail::assertNothingQueued();
})->with(['refunded', 'pending', 'cancelled']);

test('if Stripe refuses the refund, nothing changes', function () {
    $this->actingAs(User::factory()->create());
    $sale = ($this->sale)();
    $this->mock(PaymentGateway::class)->shouldReceive('refund')->andThrow(new RuntimeException('Stripe is down.'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->post(route('sales.refund', $sale)))->toThrow(RuntimeException::class);

    expect($sale->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($sale->payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($sale->items->first()->fresh()->voided_at)->toBeNull()
        ->and($sale->items->first()->artPiece->fresh()->status)->toBe(ArtPieceStatus::Sold);
    Mail::assertNothingQueued();
});
