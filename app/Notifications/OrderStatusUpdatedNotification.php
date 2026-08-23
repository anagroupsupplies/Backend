<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusUpdatedNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $status  The new status being reported.
     * @param  string|null  $shopName  Set when only one shop's part of the order moved.
     */
    public function __construct(
        public Order $order,
        public string $status,
        public ?string $shopName = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = [
            'order' => $this->order,
            'customer' => $notifiable,
            'status' => $this->status,
            'shopName' => $this->shopName,
            'headline' => $this->headline(),
            'body' => $this->body(),
        ];

        return (new MailMessage)
            ->subject($this->subject())
            ->view('emails.order-status-updated', $data)
            ->text('emails.text.order-status-updated', $data);
    }

    private function subject(): string
    {
        return match ($this->status) {
            'confirmed' => "Order {$this->order->number} is confirmed",
            'processing' => "Order {$this->order->number} is being prepared",
            'shipped' => "Order {$this->order->number} is on the way",
            'delivered' => "Order {$this->order->number} has been delivered",
            'cancelled' => "Order {$this->order->number} was cancelled",
            default => "Update on order {$this->order->number}",
        };
    }

    private function headline(): string
    {
        return match ($this->status) {
            'confirmed' => 'Your order is confirmed ✅',
            'processing' => 'Your order is being prepared 📦',
            'shipped' => 'Your order is on the way 🚚',
            'delivered' => 'Your order has arrived 🎉',
            'cancelled' => 'Your order was cancelled',
            default => 'Your order has been updated',
        };
    }

    private function body(): string
    {
        $part = $this->shopName ? "The items from {$this->shopName} in your order" : 'Your order';

        return match ($this->status) {
            'confirmed' => "{$part} has been confirmed by the seller and will be prepared shortly.",
            'processing' => "{$part} is being packed and made ready for delivery.",
            'shipped' => "{$part} has left the shop and is on its way to your delivery address.",
            'delivered' => "{$part} has been delivered. We hope you love it!",
            'cancelled' => "{$part} has been cancelled. If you have already paid, our team will be in touch about your refund.",
            default => "{$part} has been updated.",
        };
    }
}
