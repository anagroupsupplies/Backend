@php
    $shipping = $order->shipping_details ?? [];
    $accent = $status === 'cancelled' ? '#b91c1c' : ($status === 'delivered' ? '#047857' : '#b45309');
    $tint = $status === 'cancelled' ? '#fef2f2' : ($status === 'delivered' ? '#ecfdf5' : '#fff7df');
    $steps = ['confirmed' => 'Confirmed', 'processing' => 'Preparing', 'shipped' => 'On the way', 'delivered' => 'Delivered'];
    $reached = array_search($status, array_keys($steps), true);
@endphp
<x-email-layout title="Order update" preview="{{ $order->number }} is now {{ $status }}.">
    <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:{{ $tint }};color:{{ $accent }};font-size:12px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;">{{ $status }}</div>
    <h1 style="margin:22px 0 12px;color:#1c1917;font-size:28px;line-height:35px;letter-spacing:-.7px;">{{ $headline }}</h1>
    <p style="margin:0 0 16px;color:#57534e;font-size:16px;line-height:26px;">Hi {{ $shipping['fullName'] ?? $customer->name }}, {{ $body }}</p>

    @if ($status !== 'cancelled' && $reached !== false)
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:22px 0;border-collapse:collapse;">
            <tr>
                @foreach ($steps as $key => $label)
                    @php $done = array_search($key, array_keys($steps), true) <= $reached; @endphp
                    <td align="center" style="width:25%;padding:0 2px;">
                        <div style="height:6px;border-radius:999px;background:{{ $done ? $accent : '#f0e7d8' }};font-size:0;line-height:0;">&nbsp;</div>
                        <div style="margin-top:8px;color:{{ $done ? $accent : '#a8a29e' }};font-size:11px;font-weight:{{ $done ? '700' : '500' }};">{{ $label }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    <div style="padding:16px 18px;border-radius:14px;background:#fafaf9;color:#57534e;font-size:14px;line-height:22px;">
        <strong style="color:#292524;">Order</strong> {{ $order->number }}<br>
        <strong style="color:#292524;">Total</strong> TZS {{ number_format((float) $order->total, 0) }}<br>
        <strong style="color:#292524;">Payment</strong>
        {{ $order->payment_method === \App\Models\Order::PAYMENT_MOBILE_MONEY ? 'Mobile money ('.($order->isPaid() ? 'paid' : 'not yet paid').')' : ($order->isPaid() ? 'Cash on delivery (collected)' : 'Cash on delivery') }}
        @if (! empty($shipping['streetAddress']))
            <br><br><strong style="color:#292524;">Delivery address</strong><br>
            {{ $shipping['streetAddress'] }}<br>
            {{ trim(($shipping['city'] ?? '').', '.($shipping['state'] ?? ''), ', ') }}
        @endif
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:20px 0 0;">
        <a href="{{ rtrim(config('app.frontend_url'), '/') }}/orders/{{ $order->id }}" style="display:inline-block;padding:14px 26px;border-radius:12px;background:{{ $accent }};color:#ffffff;font-size:15px;font-weight:750;text-decoration:none;">Track my order →</a>
    </td></tr></table>

    <p style="margin:25px 0 0;color:#57534e;font-size:15px;line-height:24px;">Thank you for shopping with us,<br><strong style="color:#b45309;">The Antenkayume team</strong></p>
</x-email-layout>
