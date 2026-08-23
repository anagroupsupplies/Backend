@php $shipping = $order->shipping_details ?? []; @endphp
{{ $headline }}

Hi {{ $shipping['fullName'] ?? $customer->name }}, {{ $body }}

Order: {{ $order->number }}
Status: {{ ucfirst($status) }}
Total: TZS {{ number_format((float) $order->total, 0) }}
Payment: {{ $order->payment_method === \App\Models\Order::PAYMENT_MOBILE_MONEY ? 'Mobile money ('.($order->isPaid() ? 'paid' : 'not yet paid').')' : ($order->isPaid() ? 'Cash on delivery (collected)' : 'Cash on delivery') }}
@if (! empty($shipping['streetAddress']))

Delivery address:
{{ $shipping['streetAddress'] }}
{{ trim(($shipping['city'] ?? '').', '.($shipping['state'] ?? ''), ', ') }}
@endif

Track your order: {{ rtrim(config('app.frontend_url'), '/') }}/orders/{{ $order->id }}

The Antenkayume team
