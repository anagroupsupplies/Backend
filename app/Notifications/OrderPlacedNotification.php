<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPlacedNotification extends Notification
{
    use Queueable;

    public function __construct(public Order $order) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order {$this->order->number} received - Antenkayume Shop")
            ->view('emails.order-placed', ['order' => $this->order, 'customer' => $notifiable])
            ->text('emails.text.order-placed', ['order' => $this->order, 'customer' => $notifiable]);
    }
}
