{{ $address['name'] ?? '' }}<br>
{{ $address['line1'] ?? '' }}<br>
@if (! empty($address['line2']))
{{ $address['line2'] }}<br>
@endif
{{ $address['city'] ?? '' }}, {{ $address['state'] ?? '' }} {{ $address['postal_code'] ?? '' }}
