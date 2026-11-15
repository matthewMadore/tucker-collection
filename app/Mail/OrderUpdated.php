<?php

namespace App\Mail;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the buyer when staff ship or refund their order.
 */
class OrderUpdated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** The status this email announces, fixed when it's queued. */
    public OrderStatus $status;

    public function __construct(public Order $order)
    {
        $this->status = $order->status;
        // Sent only if the ship/refund transaction commits.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->status === OrderStatus::Fulfilled
            ? 'Your order has shipped'
            : 'Your order has been refunded');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.order-updated', with: [
            'shipped' => $this->status === OrderStatus::Fulfilled,
            'pieceName' => $this->order->items()->value('name_snapshot'),
            'trackingNumber' => $this->order->shipment?->tracking_number,
            'orderUrl' => $this->order->viewUrl(),
        ]);
    }
}
