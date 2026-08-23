<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketRepliedNotification extends Notification
{
    use Queueable;

    public function __construct(public Ticket $ticket, public string $message, public string $authorName) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = ['ticket' => $this->ticket, 'body' => $this->message, 'authorName' => $this->authorName, 'recipient' => $notifiable];

        return (new MailMessage)
            ->subject("New reply on {$this->ticket->reference}: {$this->ticket->subject}")
            ->view('emails.ticket-replied', $data)
            ->text('emails.text.ticket-replied', $data);
    }
}
