<?php

namespace App\Services;

use App\Models\EscrowHolding;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use Throwable;

/**
 * The short list of events worth an SMS.
 *
 * Email carries the detail; SMS is reserved for the few moments where a
 * customer or seller genuinely needs to know now and may not be at their inbox.
 * Every send is best-effort and wrapped, so a failed message can never roll
 * back an order, a payment or a delivery update.
 */
class SmsNotifier
{
    private const PLATFORM_NAME = 'Antenkayume';

    public function __construct(private readonly AutoFayaSmsService $sms) {}

    /** Confirms to the buyer that the order actually landed. */
    public function orderPlaced(Order $order): void
    {
        $this->send(
            $this->buyerPhone($order),
            "Order {$order->number} received. Total TZS ".$this->money($order->total).'. '
                .($order->isPaidOnDelivery()
                    ? 'Please have the cash ready on delivery.'
                    : 'Approve the payment prompt on your phone to confirm it.')
                .' - '.$this->shopName(),
            "order placed SMS for {$order->number}",
        );
    }

    /** Money has actually moved; the buyer should have a record of it. */
    public function paymentConfirmed(Order $order): void
    {
        $this->send(
            $this->buyerPhone($order),
            'Payment of TZS '.$this->money($order->paid_amount)." received for order {$order->number}. Thank you! - ".$this->shopName(),
            "payment confirmed SMS for {$order->number}",
        );
    }

    /**
     * Delivery progress. Only the states a customer actually acts on are sent;
     * intermediate bookkeeping changes are left to email.
     */
    public function statusUpdated(Order $order, string $status, ?string $shopName = null): void
    {
        $what = $shopName ? "Your items from {$shopName}" : "Order {$order->number}";

        $text = match ($status) {
            'shipped' => "{$what} is on the way to you.",
            'delivered' => "{$what} has been delivered. Enjoy!",
            'cancelled' => "{$what} has been cancelled. Contact us if this is unexpected.",
            default => null,
        };

        if ($text === null) {
            return;
        }

        $this->send($this->buyerPhone($order), $text.' - '.$this->shopName(), "status SMS ({$status}) for {$order->number}");
    }

    /** A seller losing a sale to a missed notification is the costly case. */
    public function newOrderForSeller(Order $order, User $seller, float $sellerTotal): void
    {
        $this->send(
            $seller->phone,
            "New order {$order->number} for your shop: TZS ".$this->money($sellerTotal).'. Open your dashboard to prepare it. - '.$this->shopName(),
            "new order SMS to seller {$seller->id} for {$order->number}",
        );
    }

    /** Money released into a seller's balance. */
    public function escrowReleased(EscrowHolding $holding, User $seller): void
    {
        $this->send(
            $seller->phone,
            'TZS '.$this->money($holding->net_amount).' has cleared and is ready for payout. Ref '.$holding->reference.'. - '.$this->shopName(),
            "escrow released SMS for {$holding->reference}",
        );
    }

    /** A payout actually sent. */
    public function payoutPaid(Payout $payout, User $seller): void
    {
        $this->send(
            $seller->phone,
            'Payout '.$payout->reference.' of TZS '.$this->money($payout->amount).' has been sent to you. - '.$this->shopName(),
            "payout SMS for {$payout->reference}",
        );
    }

    private function buyerPhone(Order $order): ?string
    {
        return $order->shipping_details['phone'] ?? $order->user?->phone;
    }

    /**
     * The platform name signed at the end of every message.
     *
     * Deliberately not the AutoFaya sender name: that is an operator-registered
     * header, often shortened or shared, so the body must still tell the
     * customer who is actually texting them.
     */
    private function shopName(): string
    {
        $name = trim((string) (Setting::general()['businessName'] ?? ''));

        return $name !== '' ? $name : self::PLATFORM_NAME;
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 0);
    }

    private function send(?string $phone, string $message, string $description): void
    {
        if (! $this->sms->isAvailable() || ! $phone) {
            return;
        }

        try {
            $this->sms->send($phone, $message);
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning("Failed to send {$description}: {$exception->getMessage()}");
        }
    }
}
