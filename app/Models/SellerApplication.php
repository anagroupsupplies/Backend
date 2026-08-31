<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerApplication extends Model
{
    /** Submitted, waiting for an administrator to look at it. */
    public const STATUS_PENDING = 'pending';

    /** An administrator asked the applicant for something more. */
    public const STATUS_MORE_INFO = 'more_info_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_MORE_INFO, self::STATUS_APPROVED, self::STATUS_REJECTED];

    public const ID_TYPES = ['nida', 'passport', 'driving_licence', 'voter_id'];

    public const PAYOUT_METHODS = ['mobile_money', 'bank_transfer'];

    protected $guarded = [];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'id_document_type' => 'nida',
        'payout_method' => 'mobile_money',
    ];

    protected function casts(): array
    {
        return ['terms_accepted_at' => 'datetime', 'reviewed_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    /** Still going through review, so the applicant cannot start a second one. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_MORE_INFO], true);
    }

    /** The applicant may edit and resubmit only while more information is wanted. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_MORE_INFO;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
