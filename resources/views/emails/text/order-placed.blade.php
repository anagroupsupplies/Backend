@php $shipping = $order->shipping_details ?? []; @endphp
Thank you, {{ $shipping['fullName'] ?? $customer->name }}!

We have received your order {{ $order->number }}.

YOUR ORDER
@foreach ($order->items as $item)
- {{ $item->name }} x{{ $item->quantity }}@if ($item->selected_size && $item->selected_size !== 'none') (size {{ $item->selected_size }})@endif - TZS {{ number_format((float) $item->unit_price * $item->quantity, 0) }}
@endforeach

Total: TZS {{ number_format((float) $order->total, 0) }}

PAYMENT
@if ($order->payment_method === \App\Models\Order::PAYMENT_MOBILE_MONEY)
@if ($order->isPaid())
Mobile money - payment received. Thank you!
@else
Mobile money - please approve the payment prompt sent to your phone.
@endif
@else
Pay on delivery - please have TZS {{ number_format((float) $order->total, 0) }} ready when your order arrives.
@endif

DELIVERY ADDRESS
{{ $shipping['streetAddress'] ?? '' }}
{{ trim(($shipping['city'] ?? '').', '.($shipping['state'] ?? '').' '.($shipping['postalCode'] ?? ''), ', ') }}
{{ $shipping['country'] ?? '' }}
@if (! empty($shipping['deliveryNotes']))
Notes: {{ $shipping['deliveryNotes'] }}
@endif

The Antenkayume team
