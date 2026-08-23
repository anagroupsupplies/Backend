@php
    $link = rtrim(config('app.frontend_url'), '/').($forShop ? '/seller/tickets/' : '/support/').$ticket->id;
@endphp
<x-email-layout title="Support ticket {{ $ticket->reference }}" preview="{{ $ticket->subject }}">
    <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#fff7df;color:#b45309;font-size:12px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;">{{ $forShop ? 'New ticket 💬' : 'Ticket received ✅' }}</div>
    <h1 style="margin:22px 0 12px;color:#1c1917;font-size:26px;line-height:33px;letter-spacing:-.6px;">{{ $ticket->subject }}</h1>

    @if ($forShop)
        <p style="margin:0 0 16px;color:#57534e;font-size:16px;line-height:26px;">{{ $ticket->user?->name ?? 'A customer' }} has opened a support ticket. Please reply as soon as you can.</p>
    @else
        <p style="margin:0 0 16px;color:#57534e;font-size:16px;line-height:26px;">Thank you for getting in touch. Your request has been sent to {{ $ticket->shop?->name ?? 'our support team' }} and you will get an email as soon as they reply.</p>
    @endif

    <div style="padding:16px 18px;border-radius:14px;background:#fafaf9;color:#57534e;font-size:14px;line-height:22px;">
        <strong style="color:#292524;">Reference</strong> {{ $ticket->reference }}<br>
        <strong style="color:#292524;">Category</strong> {{ ucfirst($ticket->category) }}
        @if ($ticket->order)
            <br><strong style="color:#292524;">Order</strong> {{ $ticket->order->number }}
        @endif
        @if ($ticket->product)
            <br><strong style="color:#292524;">Product</strong> {{ $ticket->product->name }}
        @endif
    </div>

    <div style="margin:18px 0;padding:16px 18px;border-left:3px solid #f59e0b;background:#fffdf7;color:#292524;font-size:15px;line-height:24px;white-space:pre-line;">{{ $body }}</div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:14px 0 0;">
        <a href="{{ $link }}" style="display:inline-block;padding:14px 26px;border-radius:12px;background:#f59e0b;color:#ffffff;font-size:15px;font-weight:750;text-decoration:none;">{{ $forShop ? 'Reply to this ticket →' : 'View my ticket →' }}</a>
    </td></tr></table>

    <p style="margin:25px 0 0;color:#57534e;font-size:15px;line-height:24px;"><strong style="color:#b45309;">The Antenkayume team</strong></p>
</x-email-layout>
