<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketOpenedNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $audience  'shop' for the seller/support side, 'customer' for the requester.
     */
    public function __construct(public Ticket $ticket, public string $message, public string $audience = 'shop') {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $forShop = $this->audience === 'shop';
        $data = ['ticket' => $this->ticket, 'body' => $this->message, 'recipient' => $notifiable, 'forShop' => $forShop];

        return (new MailMessage)
            ->subject($forShop
                ? "New support ticket {$this->ticket->reference}: {$this->ticket->subject}"
                : "We received your request {$this->ticket->reference}")
            ->view('emails.ticket-opened', $data)
            ->text('emails.text.ticket-opened', $data);
    }
}
