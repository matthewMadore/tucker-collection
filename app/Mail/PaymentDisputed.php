<?php

namespace App\Mail;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the owners when a buyer disputes a charge with their bank, and again
 * when the dispute closes. Responding (evidence) happens in Stripe.
 */
class PaymentDisputed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  'open'|'won'|'lost'  $outcome
     */
    public function __construct(
        public Order $order,
        public string $disputeId,
        public string $outcome,
        public string $reason,
        public ?CarbonImmutable $respondBy = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $piece = $this->order->items()->value('name_snapshot');

        return new Envelope(subject: match ($this->outcome) {
            'won' => "Dispute won: {$piece}",
            'lost' => "Dispute lost: {$piece}",
            default => "Payment disputed: {$piece}",
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-disputed', with: [
            'pieceName' => $this->order->items()->value('name_snapshot'),
            'disputeUrl' => "https://dashboard.stripe.com/disputes/{$this->disputeId}",
        ]);
    }
}
