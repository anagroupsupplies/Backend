<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewOrderForSellerNotification extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, OrderItem>  $items  Only the lines belonging to this seller.
     */
    public function __construct(public Order $order, public Collection $items) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sellerTotal = $this->items->sum(fn ($item) => (float) $item->unit_price * $item->quantity);

        return (new MailMessage)
            ->subject("New order {$this->order->number} for your shop")
            ->view('emails.new-order-seller', ['order' => $this->order, 'items' => $this->items, 'seller' => $notifiable, 'sellerTotal' => $sellerTotal])
            ->text('emails.text.new-order-seller', ['order' => $this->order, 'items' => $this->items, 'seller' => $notifiable, 'sellerTotal' => $sellerTotal]);
    }
}
