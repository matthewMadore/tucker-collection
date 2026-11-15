<x-mail::message>
@if ($outcome === 'won')
# Dispute won: {{ $pieceName }}

The bank sided with you. The ${{ number_format($order->total_cents / 100, 2) }} payment from {{ $order->buyer_email }} is yours again.
@elseif ($outcome === 'lost')
# Dispute lost: {{ $pieceName }}

The bank returned ${{ number_format($order->total_cents / 100, 2) }} to {{ $order->buyer_email }}. The order is now marked refunded{{ $order->shipment?->tracking_number ? '' : ' and the piece is back on sale' }}.
@else
# A buyer disputed a payment

{{ $order->buyer_email }} asked their bank to reverse the ${{ number_format($order->total_cents / 100, 2) }} payment for **{{ $pieceName }}** (reason: {{ str_replace('_', ' ', $reason) }}).

@if ($respondBy)
Respond in Stripe by **{{ $respondBy->format('F j, Y') }}** with evidence such as the tracking number and delivery confirmation, or the bank decides without it.
@endif

Until it's settled, the order can't be shipped or refunded in the app.
@endif

<x-mail::button :url="$disputeUrl">
Open the dispute in Stripe
</x-mail::button>
</x-mail::message>
