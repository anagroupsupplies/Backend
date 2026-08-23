@php
    $shipping = $order->shipping_details ?? [];
    $isMobileMoney = $order->payment_method === \App\Models\Order::PAYMENT_MOBILE_MONEY;
@endphp
<x-email-layout title="Order received" preview="We have received order {{ $order->number }}.">
    <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#fff7df;color:#b45309;font-size:12px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;">Order received 🛒</div>
    <h1 style="margin:22px 0 12px;color:#1c1917;font-size:28px;line-height:35px;letter-spacing:-.7px;">Thank you, {{ $shipping['fullName'] ?? $customer->name }}!</h1>
    <p style="margin:0 0 16px;color:#57534e;font-size:16px;line-height:26px;">We have received your order <strong style="color:#292524;">{{ $order->number }}</strong> and it is now being prepared. Here is a summary of what you ordered.</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:18px 0;border-collapse:collapse;">
        @foreach ($order->items as $item)
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
            <td style="padding:14px 0;color:#1c1917;font-size:17px;font-weight:700;">Total</td>
            <td align="right" style="padding:14px 0;color:#b45309;font-size:17px;font-weight:700;white-space:nowrap;">TZS {{ number_format((float) $order->total, 0) }}</td>
        </tr>
    </table>

    <div style="padding:16px 18px;border-radius:14px;background:#fafaf9;color:#57534e;font-size:14px;line-height:22px;">
        <strong style="color:#292524;">Payment</strong><br>
        @if ($isMobileMoney)
            Mobile money &mdash;
            @if ($order->isPaid())
                payment received. Thank you!
            @else
                please approve the payment prompt sent to your phone. We will email you as soon as it is confirmed.
            @endif
        @else
            Pay on delivery &mdash; please have <strong>TZS {{ number_format((float) $order->total, 0) }}</strong> ready when your order arrives.
        @endif
        <br><br>
        <strong style="color:#292524;">Delivery address</strong><br>
        {{ $shipping['streetAddress'] ?? '' }}<br>
        {{ trim(($shipping['city'] ?? '').', '.($shipping['state'] ?? '').' '.($shipping['postalCode'] ?? ''), ', ') }}<br>
        {{ $shipping['country'] ?? '' }}
        @if (! empty($shipping['deliveryNotes']))
            <br><em>Notes: {{ $shipping['deliveryNotes'] }}</em>
        @endif
    </div>

    <p style="margin:25px 0 0;color:#57534e;font-size:15px;line-height:24px;">We will keep you posted as your order moves along.<br><strong style="color:#b45309;">The Antenkayume team</strong></p>
</x-email-layout>
