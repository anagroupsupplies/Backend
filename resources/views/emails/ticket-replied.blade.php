@php
    $isShopRecipient = $recipient->id === $ticket->seller_id || in_array($recipient->role, ['admin', 'master'], true);
    $link = rtrim(config('app.frontend_url'), '/').($isShopRecipient ? '/seller/tickets/' : '/support/').$ticket->id;
@endphp
<x-email-layout title="New reply on {{ $ticket->reference }}" preview="{{ $authorName }} replied to your ticket.">
    <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#eef2ff;color:#3549a8;font-size:12px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;">New reply 💬</div>
    <h1 style="margin:22px 0 12px;color:#1c1917;font-size:26px;line-height:33px;letter-spacing:-.6px;">{{ $ticket->subject }}</h1>
    <p style="margin:0 0 16px;color:#57534e;font-size:16px;line-height:26px;"><strong style="color:#292524;">{{ $authorName }}</strong> replied to ticket {{ $ticket->reference }}.</p>

    <div style="margin:18px 0;padding:16px 18px;border-left:3px solid #3157d5;background:#f7f9ff;color:#292524;font-size:15px;line-height:24px;white-space:pre-line;">{{ $body }}</div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:14px 0 0;">
        <a href="{{ $link }}" style="display:inline-block;padding:14px 26px;border-radius:12px;background:#3157d5;color:#ffffff;font-size:15px;font-weight:750;text-decoration:none;">Read and reply →</a>
    </td></tr></table>

    <p style="margin:25px 0 0;color:#57534e;font-size:15px;line-height:24px;"><strong style="color:#b45309;">The Antenkayume team</strong></p>
</x-email-layout>
