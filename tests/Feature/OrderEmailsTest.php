<?php

use App\Mail\OrderConfirmed;
use App\Mail\OrderReceived;
use App\Mail\OrderUpdated;
use App\Mail\PaymentDisputed;
use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->piece = ArtPiece::factory()->create(['name' => 'Charcoal Owl']);
    $this->order = Order::factory()->create([
        'status' => 'paid',
        'total_cents' => 46_500,
        'shipping_cents' => 4_500,
        'buyer_email' => 'buyer@example.com',
        // Buyer-typed text must be escaped in the email's HTML.
        'shipping_address' => ['name' => '<b>Ada</b> Buyer', 'line1' => '1 Main St', 'line2' => 'Apt 4', 'city' => 'Portland', 'state' => 'OR', 'postal_code' => '97201', 'country' => 'US'],
    ]);
    OrderItem::factory()->for($this->order)->for($this->piece)->create();
    Shipment::factory()->for($this->order)->create(['service_level' => 'UPS Ground']);
});

test('the buyer\'s confirmation has the piece, total, address and their order link', function () {
    $mail = new OrderConfirmed($this->order);

    $mail->assertHasSubject('Your order is confirmed');
    $mail->assertSeeInHtml('Charcoal Owl')
        ->assertSeeInHtml('$465.00')
        ->assertSeeInHtml('&lt;b&gt;Ada&lt;/b&gt; Buyer', false)
        ->assertSeeInHtml('Apt 4')
        ->assertSeeInHtml($this->order->viewUrl());
});

test('the owners\' notice has what sold, where to ship it and a link to the piece', function () {
    $mail = new OrderReceived($this->order);

    $mail->assertHasSubject('Sold: Charcoal Owl');
    $mail->assertSeeInHtml('$465.00')
        ->assertSeeInHtml('$45.00 of it for shipping, UPS Ground')
        ->assertSeeInHtml('&lt;b&gt;Ada&lt;/b&gt; Buyer', false)
        ->assertSeeInHtml('buyer@example.com')
        ->assertSeeInHtml(route('art-pieces.show', $this->piece));
});

test('order emails wait for the transaction, so a rolled-back change sends nothing', function (Closure $makeMail) {
    // No Mail::fake(): the sync queue and array mailer really send.
    rescue(fn () => DB::transaction(function () use ($makeMail): void {
        Mail::to('someone@example.com')->queue($makeMail($this->order));

        throw new RuntimeException('Saving the change failed.');
    }), report: false);

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);

    DB::transaction(fn () => Mail::to('someone@example.com')->queue($makeMail($this->order)));

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
})->with([
    'OrderConfirmed' => fn (Order $order) => new OrderConfirmed($order),
    'OrderReceived' => fn (Order $order) => new OrderReceived($order),
    'OrderUpdated' => fn (Order $order) => new OrderUpdated($order),
    'PaymentDisputed' => fn (Order $order) => new PaymentDisputed($order, 'dp_1', 'open', 'fraudulent'),
]);

test('the dispute email tells the owners why, by when to respond, and links to the dispute', function () {
    $mail = new PaymentDisputed($this->order, 'dp_123', 'open', 'product_not_received', CarbonImmutable::parse('2026-12-01'));

    $mail->assertHasSubject('Payment disputed: Charcoal Owl');
    $mail->assertSeeInHtml('buyer@example.com')
        ->assertSeeInHtml('$465.00')
        ->assertSeeInHtml('product not received')
        ->assertSeeInHtml('December 1, 2026')
        ->assertSeeInHtml('https://dashboard.stripe.com/disputes/dp_123');
});

test('the dispute email reports the outcome when it closes', function (string $outcome, string $subject, string $text) {
    $mail = new PaymentDisputed($this->order, 'dp_123', $outcome, 'fraudulent');

    $mail->assertHasSubject($subject);
    $mail->assertSeeInHtml($text);
})->with([
    'won' => ['won', 'Dispute won: Charcoal Owl', 'is yours again'],
    'lost' => ['lost', 'Dispute lost: Charcoal Owl', 'The bank returned $465.00'],
]);

test('the shipped email has the tracking number and a UPS tracking link', function () {
    $this->order->shipment->update(['tracking_number' => '1Z999AA10123456784']);
    $this->order->update(['status' => 'fulfilled']);
    $mail = new OrderUpdated($this->order->fresh());

    $mail->assertHasSubject('Your order has shipped');
    $mail->assertSeeInHtml('Charcoal Owl')
        ->assertSeeInHtml('1Z999AA10123456784')
        ->assertSeeInHtml('https://www.ups.com/track?tracknum=1Z999AA10123456784')
        ->assertSeeInHtml($this->order->viewUrl());
});

test('the refund email has the amount and the order link', function () {
    $this->order->update(['status' => 'refunded']);
    $mail = new OrderUpdated($this->order->fresh());

    $mail->assertHasSubject('Your order has been refunded');
    $mail->assertSeeInHtml('$465.00')
        ->assertSeeInHtml('Charcoal Owl')
        ->assertDontSeeInHtml('tracking')
        ->assertSeeInHtml($this->order->viewUrl());
});

test('an email queued when an order ships still says shipped if it\'s refunded before sending', function () {
    $this->order->update(['status' => 'fulfilled']);
    $mail = new OrderUpdated($this->order->fresh());
    $this->order->update(['status' => 'refunded']);

    // Like the queue does: the worker reloads the order from the database.
    $sent = unserialize(serialize($mail));

    expect($sent->order->status->value)->toBe('refunded');
    $sent->assertHasSubject('Your order has shipped');
});
