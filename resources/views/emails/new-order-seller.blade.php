@php
    $shipping = $order->shipping_details ?? [];
    $hasGps = $order->delivery_latitude !== null && $order->delivery_longitude !== null;
@endphp
<x-email-layout title="New order for your shop" preview="Order {{ $order->number }} needs your attention.">
    <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#ecfdf5;color:#047857;font-size:12px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;">New order 🎉</div>
    <h1 style="margin:22px 0 12px;color:#1c1917;font-size:28px;line-height:35px;letter-spacing:-.7px;">Hi {{ $seller->name }}, you have a new order</h1>
    <p style="margin:0 0 16px;color:#57534e;font-size:16px;line-height:26px;">Order <strong style="color:#292524;">{{ $order->number }}</strong> includes {{ $items->count() }} {{ \Illuminate\Support\Str::plural('item', $items->count()) }} from your shop. Please prepare it for delivery.</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:18px 0;border-collapse:collapse;">
        @foreach ($items as $item)
            <tr>
                <td style="padding:12px 0;border-bottom:1px solid #f5e7d3;color:#292524;font-size:15px;line-height:22px;">
                    <strong>{{ $item->name }}</strong><br>
                    <span style="color:#78716c;font-size:13px;">Qty {{ $item->quantity }}@if ($item->selected_size && $item->selected_size !== 'none') &middot; Size {{ $item->selected_size }}@endif</span>
                </td>
                <td align="right" style="padding:12px 0;border-bottom:1px solid #f5e7d3;color:#292524;font-size:15px;white-space:nowrap;">
                    TZS {{ number_format((float) $item->unit_price * $item->quantity, 0) }}
                </td>
            </tr>
        @endforeach
        <tr>
            <td style="padding:14px 0;color:#1c1917;font-size:17px;font-weight:700;">Your total</td>
            <td align="right" style="padding:14px 0;color:#047857;font-size:17px;font-weight:700;white-space:nowrap;">TZS {{ number_format($sellerTotal, 0) }}</td>
        </tr>
    </table>

    <div style="padding:16px 18px;border-radius:14px;background:#fafaf9;color:#57534e;font-size:14px;line-height:22px;">
        <strong style="color:#292524;">Customer</strong><br>
        {{ $shipping['fullName'] ?? 'Customer' }}<br>
        {{ $shipping['phone'] ?? '' }}<br><br>
        <strong style="color:#292524;">Deliver to</strong><br>
        {{ $shipping['streetAddress'] ?? '' }}<br>
        {{ trim(($shipping['city'] ?? '').', '.($shipping['state'] ?? ''), ', ') }}
        @if (! empty($shipping['deliveryNotes']))
            <br><em>Notes: {{ $shipping['deliveryNotes'] }}</em>
        @endif
        <br><br>
        <strong style="color:#292524;">Payment</strong><br>
        {{ $order->payment_method === \App\Models\Order::PAYMENT_MOBILE_MONEY ? 'Mobile money ('.($order->isPaid() ? 'paid' : 'awaiting payment').')' : 'Pay on delivery' }}
    </div>

    @if ($hasGps)
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:20px 0 4px;">
            <a href="https://maps.google.com/?q={{ $order->delivery_latitude }},{{ $order->delivery_longitude }}" style="display:inline-block;padding:15px 26px;border-radius:12px;background:#047857;color:#ffffff;font-size:16px;font-weight:750;text-decoration:none;">📍 Open delivery location</a>
        </td></tr></table>
        <p style="margin:6px 0 0;text-align:center;color:#a8a29e;font-size:12px;">The customer shared their exact GPS location.</p>
    @endif

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:18px 0 0;">
        <a href="{{ rtrim(config('app.frontend_url'), '/') }}/seller/orders/{{ $order->id }}" style="display:inline-block;padding:13px 24px;border-radius:12px;background:#f59e0b;color:#ffffff;font-size:15px;font-weight:750;text-decoration:none;">View order in your dashboard →</a>
    </td></tr></table>

    <p style="margin:25px 0 0;color:#57534e;font-size:15px;line-height:24px;">Happy selling,<br><strong style="color:#b45309;">The Antenkayume team</strong></p>
</x-email-layout>
