<x-mail::message>
@if ($shipped)
# Your order has shipped

**{{ $pieceName }}** is on its way.

UPS tracking number: **{{ $trackingNumber }}**

<x-mail::button :url="'https://www.ups.com/track?tracknum='.urlencode((string) $trackingNumber)">
Track your package
</x-mail::button>
@else
# Your order has been refunded

We've refunded ${{ number_format($order->total_cents / 100, 2) }} for **{{ $pieceName }}** to your card. It can take 5–10 business days to appear on your statement.
@endif

[View your order]({{ $orderUrl }})

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
