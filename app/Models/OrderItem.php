<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'fulfillment_status' => 'pending',
    ];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'product_snapshot' => 'array', 'fulfillment_updated_at' => 'datetime'];
    }

    public function lineTotal(): float
    {
        return (float) $this->unit_price * $this->quantity;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
