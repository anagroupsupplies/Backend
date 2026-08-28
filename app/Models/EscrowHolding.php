<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EscrowHolding extends Model
{
    /** Customer has paid; the shop has not delivered yet. */
    public const STATUS_HELD = 'held';

    /** Delivered; the buyer's inspection window is running. */
    public const STATUS_PENDING_RELEASE = 'pending_release';

    /** Approved for the seller; owed but not yet paid out. */
    public const STATUS_RELEASED = 'released';

    /** Included in a payout the administrator has settled. */
    public const STATUS_PAID = 'paid';

    /** Buyer raised a problem; the money is frozen until resolved. */
    public const STATUS_DISPUTED = 'disputed';

    /** Resolved in the buyer's favour. */
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_HELD, self::STATUS_PENDING_RELEASE, self::STATUS_RELEASED,
        self::STATUS_PAID, self::STATUS_DISPUTED, self::STATUS_REFUNDED,
    ];

    protected $guarded = [];

    protected $attributes = [
        'status' => self::STATUS_HELD,
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'held_at' => 'datetime',
            'delivered_at' => 'datetime',
            'releasable_at' => 'datetime',
            'released_at' => 'datetime',
            'refunded_at' => 'datetime',
            'disputed_at' => 'datetime',
        ];
    }

    /** Money the seller has earned but has not been paid yet. */
    public function scopeAwaitingPayout(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RELEASED)->whereNull('payout_id');
    }

    /** Money still being held on the buyer's behalf. */
    public function scopeStillHeld(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_HELD, self::STATUS_PENDING_RELEASE, self::STATUS_DISPUTED]);
    }

    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    /** A holding can only be disputed while the money is still with us. */
    public function canBeDisputed(): bool
    {
        return in_array($this->status, [self::STATUS_HELD, self::STATUS_PENDING_RELEASE], true);
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

    public function payout()
    {
        return $this->belongsTo(Payout::class);
    }
}
