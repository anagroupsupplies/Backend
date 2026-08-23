@php
    $link = rtrim(config('app.frontend_url'), '/').($forShop ? '/seller/tickets/' : '/support/').$ticket->id;
@endphp
@if ($forShop)
NEW SUPPORT TICKET

{{ $ticket->user?->name ?? 'A customer' }} has opened a support ticket. Please reply as soon as you can.
@else
TICKET RECEIVED

Thank you for getting in touch. Your request has been sent to {{ $ticket->shop?->name ?? 'our support team' }} and you will get an email as soon as they reply.
@endif

Subject: {{ $ticket->subject }}
Reference: {{ $ticket->reference }}
Category: {{ ucfirst($ticket->category) }}
@if ($ticket->order)
Order: {{ $ticket->order->number }}
@endif
@if ($ticket->product)
Product: {{ $ticket->product->name }}
@endif

MESSAGE
{{ $body }}

{{ $forShop ? 'Reply to this ticket' : 'View your ticket' }}: {{ $link }}

The Antenkayume team
