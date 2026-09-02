<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Notifications\NewOrderForSellerNotification;
use App\Notifications\OrderPaymentConfirmedNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusUpdatedNotification;
use Throwable;

/**
 * Sends the order emails.
 *
 * Delivery is best-effort: a mail failure must never roll back or block an
 * order that has already been paid for or placed.
 */
class OrderNotifier
{
    public function __construct(private readonly SmsNotifier $sms) {}

    public function orderPlaced(Order $order): void
    {
        $order->loadMissing('items', 'user');

        if ($buyer = $order->user) {
            $this->send(
                fn () => $buyer->notify(new OrderPlacedNotification($order)),
                "order placed email for {$order->number}",
            );
        }

        $this->sms->orderPlaced($order);

        foreach ($order->items->whereNotNull('seller_id')->groupBy('seller_id') as $sellerId => $items) {
            $seller = User::find($sellerId);
            if (! $seller || ! $seller->email) {
                continue;
            }
            $this->send(
                fn () => $seller->notify(new NewOrderForSellerNotification($order, $items)),
                "new order email to seller {$sellerId} for {$order->number}",
            );
            $this->sms->newOrderForSeller($order, $seller, (float) $items->sum(fn ($item) => (float) $item->unit_price * $item->quantity));
        }
    }

    /**
     * Tell the buyer their order (or one shop's part of it) moved on.
     */
    public function statusUpdated(Order $order, string $status, ?string $shopName = null): void
    {
        $order->loadMissing('user');

        if ($buyer = $order->user) {
            $this->send(
                fn () => $buyer->notify(new OrderStatusUpdatedNotification($order, $status, $shopName)),
                "status update email ({$status}) for {$order->number}",
            );
        }

        $this->sms->statusUpdated($order, $status, $shopName);
    }

    public function paymentConfirmed(Order $order): void
    {
        $order->loadMissing('user');

        if ($buyer = $order->user) {
            $this->send(
                fn () => $buyer->notify(new OrderPaymentConfirmedNotification($order)),
                "payment confirmation email for {$order->number}",
            );
        }

        $this->sms->paymentConfirmed($order);
    }

    private function send(callable $callback, string $description): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning("Failed to send {$description}: {$exception->getMessage()}");
        }
    }
}
