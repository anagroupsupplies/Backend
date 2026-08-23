<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    /** Awaiting a reply from the shop or support. */
    public const STATUS_OPEN = 'open';

    /** Answered; awaiting the customer. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_PENDING, self::STATUS_RESOLVED, self::STATUS_CLOSED];

    public const CATEGORIES = ['order', 'delivery', 'payment', 'product', 'refund', 'account', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $guarded = [];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'category' => 'other',
        'priority' => 'normal',
    ];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /**
     * Restrict a query to the tickets this account is allowed to see: an
     * administrator sees everything, a seller only the tickets addressed to
     * their shop, and a customer only their own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isSeller()) {
            return $query->where(fn (Builder $q) => $q->where('seller_id', $user->id)->orWhere('user_id', $user->id));
        }

        return $query->where('user_id', $user->id);
    }

    public function isParticipant(User $user): bool
    {
        return $user->isAdmin() || $this->user_id === $user->id || $this->seller_id === $user->id;
    }

    /** Only staff may read internal notes or change status freely. */
    public function isStaff(User $user): bool
    {
        return $user->isAdmin() || $this->seller_id === $user->id;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    public function messages()
    {
        return $this->hasMany(TicketMessage::class)->oldest();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
