<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read-only access to the audit trail. Gated to the master administrator by the
 * `master` middleware on the route; there is deliberately no write endpoint.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', Rule::in(AuditLogger::ACTIONS)],
            'actorId' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'perPage' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = AuditLog::query()->latest('created_at')->latest('id');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($outer) use ($search): void {
                $outer->where('actor_name', 'like', "%{$search}%")
                    ->orWhere('actor_email', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }
        if ($action = $filters['action'] ?? null) {
            $query->where('action', $action);
        }
        if ($actorId = $filters['actorId'] ?? null) {
            $query->where('user_id', $actorId);
        }
        if ($from = $filters['from'] ?? null) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $filters['to'] ?? null) {
            $query->whereDate('created_at', '<=', $to);
        }

        $logs = $query->paginate($filters['perPage'] ?? 25)->withQueryString();

        return response()->json(['data' => [
            'logs' => collect($logs->items())->map(fn (AuditLog $log) => $this->data($log)),
            'meta' => ['total' => $logs->total(), 'perPage' => $logs->perPage(), 'currentPage' => $logs->currentPage(), 'lastPage' => $logs->lastPage()],
            'actions' => AuditLog::query()->select('action', DB::raw('COUNT(*) as total'))->groupBy('action')->orderByDesc('total')->pluck('total', 'action'),
            'actors' => AuditLog::query()->whereNotNull('user_id')->select('user_id', 'actor_name')->distinct()->get()->map(fn ($row) => ['id' => (string) $row->user_id, 'name' => $row->actor_name]),
        ]]);
    }

    /** @return array<string, mixed> */
    private function data(AuditLog $log): array
    {
        return [
            'id' => (string) $log->id,
            'action' => $log->action,
            'actor' => [
                'id' => $log->user_id ? (string) $log->user_id : null,
                'name' => $log->actor_name,
                'email' => $log->actor_email,
                'role' => $log->actor_role,
            ],
            'subject' => $log->auditable_type ? ['type' => class_basename($log->auditable_type), 'id' => (string) $log->auditable_id] : null,
            'description' => $log->description,
            'changes' => $log->changes,
            'ipAddress' => $log->ip_address,
            'userAgent' => $log->user_agent,
            'createdAt' => $log->created_at,
        ];
    }
}
