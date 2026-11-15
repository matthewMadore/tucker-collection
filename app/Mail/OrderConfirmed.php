<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the buyer once the webhook marks the order paid, with a signed link to
 * the order page. (Stripe's own receipt covers the charge itself.)
 */
class OrderConfirmed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        // Queued only once the payment transaction commits: a rolled-back
        // payment never emails, and a mail outage never rolls one back.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your order is confirmed');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.order-confirmed', with: [
            'pieceName' => $this->order->items()->value('name_snapshot'),
            'orderUrl' => $this->order->viewUrl(),
        ]);
    }
}
