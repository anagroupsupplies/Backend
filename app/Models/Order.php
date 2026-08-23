<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const PAYMENT_ON_DELIVERY = 'cash_on_delivery';

    public const PAYMENT_MOBILE_MONEY = 'mobile_money';

    /** Payment methods a buyer may choose at checkout. */
    public const PAYMENT_METHODS = [self::PAYMENT_ON_DELIVERY, self::PAYMENT_MOBILE_MONEY];

    /**
     * Fulfilment statuses, ordered from least to most advanced. An order's
     * overall status is the least advanced of its (non-cancelled) lines.
     */
    public const STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];

    /** Statuses a seller may set on their own lines. */
    public const SELLER_STATUSES = ['confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];

    /** Awaiting cash on delivery. */
    public const PAY_STATUS_PENDING = 'pending';

    /** Mobile money push sent, waiting for the customer to approve. */
    public const PAY_STATUS_PROCESSING = 'processing';

    public const PAY_STATUS_PAID = 'paid';

    public const PAY_STATUS_PARTIAL = 'partial';

    public const PAY_STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $attributes = [
        'status' => 'pending',
        'payment_method' => self::PAYMENT_ON_DELIVERY,
        'payment_status' => self::PAY_STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return ['shipping_details' => 'array', 'subtotal' => 'decimal:2', 'shipping_total' => 'decimal:2', 'total' => 'decimal:2', 'delivery_latitude' => 'float', 'delivery_longitude' => 'float', 'delivery_accuracy' => 'float', 'paid_amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function isPaidOnDelivery(): bool
    {
        return $this->payment_method === self::PAYMENT_ON_DELIVERY;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAY_STATUS_PAID;
    }

    /**
     * Only money actually collected counts as revenue. A pending or
     * awaiting-payment order has not been paid for, so it is excluded.
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('payment_status', self::PAY_STATUS_PAID);
    }

    public function markPaid(float $amount, ?string $transactionId = null, ?string $channel = null): void
    {
        $this->forceFill([
            'payment_status' => self::PAY_STATUS_PAID,
            'paid_amount' => $amount,
            'paid_at' => $this->paid_at ?? now(),
            'payment_transaction_id' => $transactionId ?? $this->payment_transaction_id,
            'payment_channel' => $channel ?? $this->payment_channel,
            'payment_failure_reason' => null,
            'status' => $this->status === 'pending' ? 'confirmed' : $this->status,
        ])->save();
    }

    /**
     * Recalculate the order status from its lines. With several sellers on one
     * order the order as a whole is only as far along as its slowest line, and
     * is cancelled only when every line is.
     *
     * @return bool True when the status actually changed.
     */
    public function syncStatusFromItems(): bool
    {
        $statuses = $this->items()->pluck('fulfillment_status');

        if ($statuses->isEmpty()) {
            return false;
        }

        $active = $statuses->reject(fn (string $status): bool => $status === 'cancelled');
        $derived = $active->isEmpty()
            ? 'cancelled'
            : collect(self::STATUSES)->first(fn (string $status): bool => $active->contains($status)) ?? $this->status;

        if ($derived === $this->status) {
            return false;
        }

        $this->forceFill(['status' => $derived])->save();

        return true;
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
