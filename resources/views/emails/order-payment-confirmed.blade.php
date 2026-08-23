@php $shipping = $order->shipping_details ?? []; @endphp
<x-email-layout title="Payment received" preview="We have received your payment for order {{ $order->number }}.">
    <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#ecfdf5;color:#047857;font-size:12px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;">Payment received ✅</div>
    <h1 style="margin:22px 0 12px;color:#1c1917;font-size:28px;line-height:35px;letter-spacing:-.7px;">Thank you, {{ $shipping['fullName'] ?? $customer->name }}!</h1>
    <p style="margin:0 0 16px;color:#57534e;font-size:16px;line-height:26px;">We have received your mobile money payment of <strong style="color:#292524;">TZS {{ number_format((float) $order->paid_amount, 0) }}</strong> for order <strong style="color:#292524;">{{ $order->number }}</strong>. Your order is confirmed and is being prepared for delivery.</p>

    <div style="padding:16px 18px;border-radius:14px;background:#fafaf9;color:#57534e;font-size:14px;line-height:22px;">
        <strong style="color:#292524;">Payment reference</strong><br>
        {{ $order->payment_reference }}
        @if ($order->payment_channel)
            <br><strong style="color:#292524;">Paid with</strong><br>{{ $order->payment_channel }}
        @endif
    </div>

    <p style="margin:25px 0 0;color:#57534e;font-size:15px;line-height:24px;">Keep this email as your receipt.<br><strong style="color:#b45309;">The Antenkayume team</strong></p>
</x-email-layout>
