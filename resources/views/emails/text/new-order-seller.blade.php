@php $shipping = $order->shipping_details ?? []; @endphp
Hi {{ $seller->name }}, you have a new order.

Order {{ $order->number }} includes {{ $items->count() }} item(s) from your shop.

ITEMS
@foreach ($items as $item)
- {{ $item->name }} x{{ $item->quantity }}@if ($item->selected_size && $item->selected_size !== 'none') (size {{ $item->selected_size }})@endif - TZS {{ number_format((float) $item->unit_price * $item->quantity, 0) }}
@endforeach

Your total: TZS {{ number_format($sellerTotal, 0) }}

CUSTOMER
{{ $shipping['fullName'] ?? 'Customer' }}
{{ $shipping['phone'] ?? '' }}

DELIVER TO
{{ $shipping['streetAddress'] ?? '' }}
{{ trim(($shipping['city'] ?? '').', '.($shipping['state'] ?? ''), ', ') }}
@if (! empty($shipping['deliveryNotes']))
Notes: {{ $shipping['deliveryNotes'] }}
@endif
@if ($order->delivery_latitude !== null)
GPS: https://maps.google.com/?q={{ $order->delivery_latitude }},{{ $order->delivery_longitude }}
@endif

PAYMENT
{{ $order->payment_method === \App\Models\Order::PAYMENT_MOBILE_MONEY ? 'Mobile money ('.($order->isPaid() ? 'paid' : 'awaiting payment').')' : 'Pay on delivery' }}

View the order: {{ rtrim(config('app.frontend_url'), '/') }}/seller/orders/{{ $order->id }}

The Antenkayume team
