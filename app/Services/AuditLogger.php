<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writes the audit trail.
 *
 * Recording is best-effort: failing to write a log entry must never break or
 * roll back the action the administrator actually performed.
 */
class AuditLogger
{
    /** Privileged actions worth keeping a permanent record of. */
    public const ACTIONS = [
        'user.role_changed', 'user.status_changed', 'user.deleted',
        'settings.updated', 'settings.mobile_money_toggled',
        'order.status_changed', 'order.payment_confirmed', 'order.payment_failed',
        'product.created', 'product.updated', 'product.deleted',
        'shop.status_changed', 'review.deleted', 'media.deleted',
        'auth.admin_signed_in',
        'escrow.opened', 'escrow.pending_release', 'escrow.released',
        'escrow.disputed', 'escrow.refunded',
        'payout.created', 'payout.paid', 'payout.cancelled',
        'seller_application.submitted', 'seller_application.approved',
        'seller_application.rejected', 'seller_application.info_requested',
    ];

    /**
     * @param  array<string, mixed>  $changes  Typically ['field' => ['from' => x, 'to' => y]].
     */
    public function record(string $action, ?Model $subject = null, array $changes = [], ?string $description = null, ?User $actor = null): void
    {
        try {
            $actor ??= Auth::user();
            $request = request();

            AuditLog::create([
                'user_id' => $actor?->getKey(),
                'actor_name' => $actor?->name ?? 'System',
                'actor_email' => $actor?->email,
                'actor_role' => $actor?->role ?? 'system',
                'action' => $action,
                'auditable_type' => $subject ? $subject::class : null,
                'auditable_id' => $subject?->getKey(),
                'description' => $description,
                'changes' => $changes ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => str($request?->userAgent() ?? '')->limit(500)->value() ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Build a from/to diff, keeping only the fields that actually changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            $previous = $before[$field] ?? null;
            if ($previous != $value) {
                $changes[$field] = ['from' => $previous, 'to' => $value];
            }
        }

        return $changes;
    }
}
