<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const PAYMENT_ON_DELIVERY = 'cash_on_delivery';

    public const PAYMENT_MOBILE_MONEY = 'mobile_money';

    /** Payment methods a buyer may choose at checkout. */
    public const PAYMENT_METHODS = [self::PAYMENT_ON_DELIVERY, self::PAYMENT_MOBILE_MONEY];

    protected $guarded = [];

    protected $attributes = [
        'status' => 'pending',
        'payment_method' => self::PAYMENT_ON_DELIVERY,
        'payment_status' => 'pending',
    ];

    protected function casts(): array
    {
        return ['shipping_details' => 'array', 'subtotal' => 'decimal:2', 'shipping_total' => 'decimal:2', 'total' => 'decimal:2', 'delivery_latitude' => 'float', 'delivery_longitude' => 'float', 'delivery_accuracy' => 'float'];
    }

    public function isPaidOnDelivery(): bool
    {
        return $this->payment_method === self::PAYMENT_ON_DELIVERY;
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
