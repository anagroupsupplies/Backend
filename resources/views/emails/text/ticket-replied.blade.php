@php
    $isShopRecipient = $recipient->id === $ticket->seller_id || in_array($recipient->role, ['admin', 'master'], true);
    $link = rtrim(config('app.frontend_url'), '/').($isShopRecipient ? '/seller/tickets/' : '/support/').$ticket->id;
@endphp
NEW REPLY ON {{ $ticket->reference }}

{{ $authorName }} replied to your ticket "{{ $ticket->subject }}".

REPLY
{{ $body }}

Read and reply: {{ $link }}

The Antenkayume team
