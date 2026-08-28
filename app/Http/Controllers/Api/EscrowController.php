<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EscrowHolding;
use App\Models\Payout;
use App\Models\User;
use App\Services\EscrowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Buyer protection, seller balances and administrator payouts over one escrow
 * ledger. As with tickets, who may see or touch a holding is decided here
 * rather than by which endpoint is called.
 */
class EscrowController extends Controller
{
    public function __construct(private readonly EscrowService $escrow) {}

    /**
     * Holdings visible to the caller: a customer sees the money held on their
     * own orders, a seller sees what is owed to their shop, an administrator
     * sees everything.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(EscrowHolding::STATUSES)],
            'sellerId' => ['nullable', 'exists:users,id'],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $user = $request->user();
        $query = EscrowHolding::query()->with(['order:id,number,user_id', 'seller:id,name', 'shop:id,name'])->latest('id');

        if ($user->isAdmin()) {
            if ($sellerId = $filters['sellerId'] ?? null) {
                $query->where('seller_id', $sellerId);
            }
        } elseif ($user->isSeller()) {
            $query->where('seller_id', $user->id);
        } else {
            $query->whereHas('order', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        $holdings = $query->paginate($filters['perPage'] ?? 20)->withQueryString();

        return response()->json(['data' => [
            'holdings' => collect($holdings->items())->map(fn (EscrowHolding $h) => $this->data($h, $user)),
            'meta' => ['total' => $holdings->total(), 'perPage' => $holdings->perPage(), 'currentPage' => $holdings->currentPage(), 'lastPage' => $holdings->lastPage()],
            'summary' => $this->summaryFor($user),
            'policy' => [
                'enabled' => $this->escrow->isEnabled(),
                'holdingDays' => $this->escrow->holdingDays(),
                'commissionRate' => $this->escrow->commissionRate(),
            ],
        ]]);
    }

    /** The customer confirms the delivery was fine; release immediately. */
    public function confirm(Request $request, EscrowHolding $holding): JsonResponse
    {
        $this->assertBuyer($request, $holding);
        abort_unless(in_array($holding->status, [EscrowHolding::STATUS_HELD, EscrowHolding::STATUS_PENDING_RELEASE], true), 422, 'This payment has already been settled.');

        $this->escrow->release($holding, 'buyer_confirmed', $request->user());

        return response()->json(['data' => $this->data($holding->refresh(), $request->user())]);
    }

    /** The customer reports a problem, which freezes the money. */
    public function dispute(Request $request, EscrowHolding $holding): JsonResponse
    {
        $this->assertBuyer($request, $holding);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->escrow->dispute($holding, $data['reason'], $request->user());
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['data' => $this->data($holding->refresh(), $request->user())]);
    }

    /** An administrator settles a dispute one way or the other. */
    public function resolve(Request $request, EscrowHolding $holding): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['release', 'refund'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $data['outcome'] === 'release'
                ? $this->escrow->release($holding, 'admin', $request->user())
                : $this->escrow->refund($holding, $data['note'] ?? null, $request->user());
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['data' => $this->data($holding->refresh(), $request->user())]);
    }

    /** Sellers awaiting payment, for the administrator's payout screen. */
    public function payableSellers(): JsonResponse
    {
        $sellers = User::where('role', 'seller')
            ->whereHas('escrowHoldings', fn ($q) => $q->awaitingPayout())
            ->get()
            ->map(fn (User $seller) => [
                'id' => (string) $seller->id,
                'name' => $seller->name,
                'email' => $seller->email,
                ...$this->escrow->balanceFor($seller->id),
            ]);

        return response()->json(['data' => $sellers]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $query = Payout::with(['seller:id,name,email', 'paidBy:id,name'])->latest('id');

        if (! $request->user()->isAdmin()) {
            $query->where('seller_id', $request->user()->id);
        }

        return response()->json(['data' => $query->paginate(30)->through(fn (Payout $p) => $this->payoutData($p))]);
    }

    public function createPayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sellerId' => ['required', 'exists:users,id'],
            'method' => ['nullable', Rule::in(Payout::METHODS)],
            'destination' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payout = $this->escrow->createPayout((int) $data['sellerId'], $request->user(), $data['method'] ?? null, $data['destination'] ?? null, $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['data' => $this->payoutData($payout->load('seller'))], 201);
    }

    public function updatePayout(Request $request, Payout $payout): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Payout::STATUS_PAID, Payout::STATUS_CANCELLED])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payout = $data['status'] === Payout::STATUS_PAID
                ? $this->escrow->markPayoutPaid($payout, $request->user(), $data['notes'] ?? null)
                : $this->escrow->cancelPayout($payout, $request->user());
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['data' => $this->payoutData($payout->load(['seller', 'paidBy']))]);
    }

    /**
     * Only the customer who placed the order may confirm or dispute its escrow.
     */
    private function assertBuyer(Request $request, EscrowHolding $holding): void
    {
        $holding->loadMissing('order');
        abort_unless($holding->order && $holding->order->user_id === $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function summaryFor(User $user): array
    {
        if ($user->isSeller() && ! $user->isAdmin()) {
            return $this->escrow->balanceFor($user->id);
        }

        if ($user->isAdmin()) {
            return [
                'heldBalance' => round((float) EscrowHolding::stillHeld()->sum('net_amount'), 2),
                'awaitingPayout' => round((float) EscrowHolding::awaitingPayout()->sum('net_amount'), 2),
                'disputedBalance' => round((float) EscrowHolding::where('status', EscrowHolding::STATUS_DISPUTED)->sum('net_amount'), 2),
                'commissionEarned' => round((float) EscrowHolding::whereIn('status', [EscrowHolding::STATUS_RELEASED, EscrowHolding::STATUS_PAID])->sum('commission_amount'), 2),
                'disputedCount' => EscrowHolding::where('status', EscrowHolding::STATUS_DISPUTED)->count(),
            ];
        }

        return [
            'protectedBalance' => round((float) EscrowHolding::stillHeld()->whereHas('order', fn ($q) => $q->where('user_id', $user->id))->sum('gross_amount'), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function data(EscrowHolding $holding, User $viewer): array
    {
        $isBuyer = ! $viewer->isAdmin() && ! $viewer->isSeller();

        return [
            'id' => (string) $holding->id,
            'reference' => $holding->reference,
            'orderId' => (string) $holding->order_id,
            'orderNumber' => $holding->order?->number,
            'shopName' => $holding->shop?->name ?? $holding->seller?->name,
            'sellerId' => (string) $holding->seller_id,
            // A buyer's money is the gross they paid; commission is between the
            // platform and the shop, so it is not shown to the customer.
            'amount' => $isBuyer ? (float) $holding->gross_amount : (float) $holding->net_amount,
            'grossAmount' => $isBuyer ? (float) $holding->gross_amount : null,
            'commissionRate' => $isBuyer ? null : (float) $holding->commission_rate,
            'commissionAmount' => $isBuyer ? null : (float) $holding->commission_amount,
            'netAmount' => $isBuyer ? null : (float) $holding->net_amount,
            'status' => $holding->status,
            'deliveredAt' => $holding->delivered_at,
            'releasableAt' => $holding->releasable_at,
            'releasedAt' => $holding->released_at,
            'refundedAt' => $holding->refunded_at,
            'disputeReason' => $holding->dispute_reason,
            'releaseReason' => $holding->release_reason,
            'canConfirm' => $isBuyer && in_array($holding->status, [EscrowHolding::STATUS_HELD, EscrowHolding::STATUS_PENDING_RELEASE], true),
            'canDispute' => $isBuyer && $holding->canBeDisputed(),
            'createdAt' => $holding->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function payoutData(Payout $payout): array
    {
        return [
            'id' => (string) $payout->id,
            'reference' => $payout->reference,
            'seller' => $payout->seller ? ['id' => (string) $payout->seller->id, 'name' => $payout->seller->name, 'email' => $payout->seller->email] : null,
            'amount' => (float) $payout->amount,
            'holdingsCount' => $payout->holdings_count,
            'status' => $payout->status,
            'method' => $payout->method,
            'destination' => $payout->destination,
            'notes' => $payout->notes,
            'paidBy' => $payout->paidBy?->name,
            'paidAt' => $payout->paid_at,
            'createdAt' => $payout->created_at,
        ];
    }
}
