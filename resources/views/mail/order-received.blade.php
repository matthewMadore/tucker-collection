<x-mail::message>
# {{ $pieceName }} sold

Paid ${{ number_format($order->total_cents / 100, 2) }}
(${{ number_format($order->shipping_cents / 100, 2) }} of it for shipping{{ $serviceLevel ? ", {$serviceLevel}" : '' }}).

Ship to:<br>
@include('mail.partials.address', ['address' => $order->shipping_address])

Buyer: {{ $order->buyer_email }}

<x-mail::button :url="$pieceUrl">
Open the piece
</x-mail::button>
</x-mail::message>
