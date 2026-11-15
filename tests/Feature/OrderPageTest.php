<?php

use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;

beforeEach(function () {
    $this->order = Order::factory()->create([
        'status' => 'paid',
        'buyer_email' => 'buyer@example.com',
        'subtotal_cents' => 42_000,
        'shipping_cents' => 4_500,
        'total_cents' => 46_500,
        'shipping_address' => ['name' => 'Ada Buyer', 'line1' => '1 Main St', 'city' => 'Portland', 'state' => 'OR', 'postal_code' => '97201', 'country' => 'US'],
    ]);
    OrderItem::factory()->for($this->order)->for(ArtPiece::factory()->create(['name' => 'Charcoal Owl']))->create();
});

test('the signed link from the email shows the buyer their order', function () {
    $this->get($this->order->viewUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gallery/Order')
            ->where('order', [
                'status' => 'paid',
                'piece_name' => 'Charcoal Owl',
                'subtotal_cents' => 42_000,
                'shipping_cents' => 4_500,
                'total_cents' => 46_500,
                'buyer_email' => 'buyer@example.com',
                'shipping_address' => ['name' => 'Ada Buyer', 'line1' => '1 Main St', 'city' => 'Portland', 'state' => 'OR', 'postal_code' => '97201', 'country' => 'US'],
                'tracking_number' => null,
            ]));
});

test('once shipped, the order page shows the tracking number', function () {
    Shipment::factory()->for($this->order)->create(['tracking_number' => '1Z999AA10123456784']);
    $this->order->update(['status' => 'fulfilled']);

    $this->get($this->order->viewUrl())
        ->assertInertia(fn ($page) => $page
            ->where('order.status', 'fulfilled')
            ->where('order.tracking_number', '1Z999AA10123456784'));
});

test('an order page without a valid signature is forbidden', function (Closure $url) {
    $this->get($url($this->order))->assertForbidden();
})->with([
    'unsigned' => fn (Order $order) => route('orders.show', $order),
    // Someone changes the order id in their own link to look at another order.
    'signature from another order' => function (Order $order) {
        $otherOrder = Order::factory()->create();

        return str_replace("/orders/{$otherOrder->id}?", "/orders/{$order->id}?", $otherOrder->viewUrl());
    },
]);
