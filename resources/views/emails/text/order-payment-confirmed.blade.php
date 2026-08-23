@php $shipping = $order->shipping_details ?? []; @endphp
Thank you, {{ $shipping['fullName'] ?? $customer->name }}!

We have received your mobile money payment of TZS {{ number_format((float) $order->paid_amount, 0) }} for order {{ $order->number }}.

Your order is confirmed and is being prepared for delivery.

Payment reference: {{ $order->payment_reference }}
@if ($order->payment_channel)
Paid with: {{ $order->payment_channel }}
@endif

Keep this email as your receipt.

The Antenkayume team
