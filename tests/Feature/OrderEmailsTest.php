<?php

use App\Mail\OrderConfirmed;
use App\Mail\OrderReceived;
use App\Models\ArtPiece;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
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

test('order emails wait for the payment transaction, so a rolled-back payment sends nothing', function (string $mailable) {
    // No Mail::fake(): the sync queue and array mailer really send.
    rescue(fn () => DB::transaction(function () use ($mailable): void {
        Mail::to('someone@example.com')->queue(new $mailable($this->order));

        throw new RuntimeException('Marking the order paid failed.');
    }), report: false);

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);

    DB::transaction(fn () => Mail::to('someone@example.com')->queue(new $mailable($this->order)));

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
})->with([OrderConfirmed::class, OrderReceived::class]);
