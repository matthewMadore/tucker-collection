<?php

use App\Enums\PaymentStatus;
use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('an order links to its items, payment and shipment', function () {
    $piece = ArtPiece::factory()->create(['name' => 'Charcoal Owl', 'price_cents' => 4999]);
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->for($piece)->create();
    $payment = Payment::factory()->for($order)->create();
    $shipment = Shipment::factory()->for($order)->create();

    $order->load(['items.artPiece', 'payment', 'shipment']);

    expect($order->items)->toHaveCount(1)
        ->and($order->items->first()->name_snapshot)->toBe('Charcoal Owl')
        ->and($order->items->first()->price_cents_snapshot)->toBe(4999)
        ->and($order->items->first()->artPiece->is($piece))->toBeTrue()
        ->and($order->payment->is($payment))->toBeTrue()
        ->and($order->payment->amount_cents)->toBe($order->total_cents)
        ->and($order->shipment->is($shipment))->toBeTrue();
});

test('a transition can save other columns with the new status', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);

    $payment->transitionTo(PaymentStatus::Processing, ['stripe_event_id' => 'evt_123']);

    expect($payment->fresh())
        ->status->toBe(PaymentStatus::Processing)
        ->stripe_event_id->toBe('evt_123');
});

test('an illegal payment transition throws and saves nothing', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);

    expect(fn () => $payment->transitionTo(PaymentStatus::Succeeded))
        ->toThrow(DomainException::class, 'Cannot change payment from pending to succeeded.');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('the database rejects a status outside the enum', function (string $table, string $factory) {
    $row = $factory::factory()->create();

    expect(fn () => DB::table($table)->where('id', $row->id)->update(['status' => 'bogus']))
        ->toThrow(QueryException::class, '_status_valid');
})->with([
    'orders' => ['orders', Order::class],
    'payments' => ['payments', Payment::class],
    'shipments' => ['shipments', Shipment::class],
]);

test('a payment intent can only be recorded once', function () {
    Payment::factory()->create(['stripe_payment_intent_id' => 'pi_same']);

    expect(fn () => Payment::factory()->create(['stripe_payment_intent_id' => 'pi_same']))
        ->toThrow(QueryException::class, 'payments_stripe_payment_intent_id_unique');
});

test('a piece can be on only one live order, but can be bought again once that item is voided', function () {
    $piece = ArtPiece::factory()->create();
    $first = OrderItem::factory()->for($piece)->create();

    // A savepoint, so Postgres lets the test continue after the rejected insert.
    expect(fn () => DB::transaction(fn () => OrderItem::factory()->for($piece)->create()))
        ->toThrow(QueryException::class, 'order_items_piece_once_live');

    $first->update(['voided_at' => now()]);

    expect(OrderItem::factory()->for($piece)->create()->exists)->toBeTrue();
});

test('the stripe event ledger records each event id once', function () {
    $record = fn () => DB::table('stripe_events_processed')->insertOrIgnore([
        'stripe_event_id' => 'evt_123',
        'type' => 'payment_intent.succeeded',
    ]);

    expect($record())->toBe(1)
        ->and($record())->toBe(0);
});
