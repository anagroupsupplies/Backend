<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPaymentConfirmedNotification extends Notification
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
            ->subject("Payment received for order {$this->order->number}")
            ->view('emails.order-payment-confirmed', ['order' => $this->order, 'customer' => $notifiable])
            ->text('emails.text.order-payment-confirmed', ['order' => $this->order, 'customer' => $notifiable]);
    }
}
