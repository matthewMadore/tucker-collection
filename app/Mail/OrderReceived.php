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
 * To the shop's owners when a piece sells: what sold and where to ship it.
 */
class OrderReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Sold: {$this->pieceName()}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.order-received', with: [
            'pieceName' => $this->pieceName(),
            'pieceUrl' => route('art-pieces.show', (int) $this->order->items()->value('art_piece_id')),
            'serviceLevel' => $this->order->shipment?->service_level,
        ]);
    }

    private function pieceName(): string
    {
        return (string) $this->order->items()->value('name_snapshot');
    }
}
