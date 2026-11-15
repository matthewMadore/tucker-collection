<x-mail::message>
# Thank you for your order

**{{ $pieceName }}** is yours. Your payment of ${{ number_format($order->total_cents / 100, 2) }} is confirmed.

It ships to:<br>
@include('mail.partials.address', ['address' => $order->shipping_address])

We'll email you the tracking number once it ships.

<x-mail::button :url="$orderUrl">
View your order
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
