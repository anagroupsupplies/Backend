<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An entry in the audit trail. Entries are append-only: an audit trail that can
 * be quietly rewritten is worth nothing, so updates and deletes are refused at
 * the model level rather than merely discouraged.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Audit log entries are append-only and cannot be modified.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Audit log entries are append-only and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }
}
