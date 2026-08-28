<?php

namespace App\Services;

use App\Models\EscrowHolding;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Holds a customer's money until their order is delivered, then releases the
 * seller's share.
 *
 * Only mobile money orders are escrowed: that is the money the platform
 * actually receives. Cash on delivery passes straight from customer to rider
 * and never sits with us, so there is nothing to hold.
 */
class EscrowService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function isEnabled(): bool
    {
        return (bool) (Setting::general()['escrowEnabled'] ?? true);
    }

    public function commissionRate(): float
    {
        return round((float) (Setting::general()['commissionRate'] ?? 0), 2);
    }

    /** Days a buyer has to inspect a delivery before funds auto-release. */
    public function holdingDays(): int
    {
        return max(0, (int) (Setting::general()['escrowHoldingDays'] ?? 3));
    }

    /**
     * Open a holding for every shop in a freshly paid order.
     *
     * Idempotent: the payment webhook is retried, so a second call must not
     * create a second holding or double the seller's balance.
     *
     * @return Collection<int, EscrowHolding>
     */
    public function openForOrder(Order $order): Collection
    {
        if (! $this->isEnabled() || ! $order->isPaid() || $order->isPaidOnDelivery()) {
            return new Collection;
        }

        $rate = $this->commissionRate();

        return DB::transaction(function () use ($order, $rate): Collection {
            $created = new Collection;

            $totals = $order->items()
                ->whereNotNull('seller_id')
                ->selectRaw('seller_id, shop_id, SUM(unit_price * quantity) as gross')
                ->groupBy('seller_id', 'shop_id')
                ->get();

            foreach ($totals as $row) {
                $gross = round((float) $row->gross, 2);
                if ($gross <= 0) {
                    continue;
                }

                $commission = round($gross * $rate / 100, 2);
                $holding = EscrowHolding::firstOrCreate(
                    ['order_id' => $order->id, 'seller_id' => $row->seller_id],
                    [
                        'reference' => 'ESC-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                        'shop_id' => $row->shop_id,
                        'gross_amount' => $gross,
                        'commission_rate' => $rate,
                        'commission_amount' => $commission,
                        'net_amount' => round($gross - $commission, 2),
                        'status' => EscrowHolding::STATUS_HELD,
                        'held_at' => now(),
                    ],
                );

                if ($holding->wasRecentlyCreated) {
                    $created->push($holding);
                    $this->audit->record('escrow.opened', $holding, [
                        'order' => $order->number,
                        'gross' => $gross,
                        'commissionRate' => $rate,
                        'net' => $holding->net_amount,
                    ], "Escrow opened for order {$order->number}");
                }
            }

            return $created;
        });
    }

    /**
     * A shop delivered its part of an order: start the buyer's inspection
     * window. Disputed money stays frozen.
     */
    public function markDelivered(Order $order, int $sellerId): ?EscrowHolding
    {
        $holding = EscrowHolding::where('order_id', $order->id)->where('seller_id', $sellerId)->first();

        if (! $holding || $holding->status !== EscrowHolding::STATUS_HELD) {
            return $holding;
        }

        $releasableAt = now()->addDays($this->holdingDays());
        $holding->forceFill([
            'status' => EscrowHolding::STATUS_PENDING_RELEASE,
            'delivered_at' => now(),
            'releasable_at' => $releasableAt,
        ])->save();

        $this->audit->record('escrow.pending_release', $holding, [
            'releasableAt' => $releasableAt->toDateTimeString(),
            'holdingDays' => $this->holdingDays(),
        ], "Delivery recorded for {$holding->reference}; funds release ".$releasableAt->diffForHumans());

        return $holding;
    }

    /**
     * Release a holding to the seller's balance.
     *
     * @param  string  $reason  'buyer_confirmed', 'auto', or 'admin'
     */
    public function release(EscrowHolding $holding, string $reason, ?User $actor = null): EscrowHolding
    {
        if ($holding->status === EscrowHolding::STATUS_RELEASED || $holding->status === EscrowHolding::STATUS_PAID) {
            return $holding;
        }
        if ($holding->isFrozen() && $reason !== 'admin') {
            throw new RuntimeException('This payment is under dispute and can only be released by an administrator.');
        }
        if ($holding->status === EscrowHolding::STATUS_REFUNDED) {
            throw new RuntimeException('This payment was already refunded to the customer.');
        }

        $holding->forceFill([
            'status' => EscrowHolding::STATUS_RELEASED,
            'released_at' => now(),
            'release_reason' => $reason,
        ])->save();

        $this->audit->record('escrow.released', $holding, [
            'reason' => $reason,
            'net' => (float) $holding->net_amount,
            'commission' => (float) $holding->commission_amount,
        ], "Released {$holding->reference} to the seller ({$reason})", $actor);

        return $holding;
    }

    /** Freeze a holding because the buyer reported a problem. */
    public function dispute(EscrowHolding $holding, string $reason, ?User $actor = null): EscrowHolding
    {
        if (! $holding->canBeDisputed()) {
            throw new RuntimeException('This payment can no longer be disputed. Please open a support ticket.');
        }

        $holding->forceFill([
            'status' => EscrowHolding::STATUS_DISPUTED,
            'dispute_reason' => $reason,
            'disputed_at' => now(),
        ])->save();

        $this->audit->record('escrow.disputed', $holding, ['reason' => $reason], "Customer disputed {$holding->reference}", $actor);

        return $holding;
    }

    /** Resolve a dispute in the customer's favour. */
    public function refund(EscrowHolding $holding, ?string $note = null, ?User $actor = null): EscrowHolding
    {
        if ($holding->status === EscrowHolding::STATUS_PAID) {
            throw new RuntimeException('This money has already been paid out and cannot be refunded here.');
        }

        $holding->forceFill([
            'status' => EscrowHolding::STATUS_REFUNDED,
            'refunded_at' => now(),
            'release_reason' => $note,
        ])->save();

        $this->audit->record('escrow.refunded', $holding, ['amount' => (float) $holding->gross_amount, 'note' => $note], "Refunded {$holding->reference} to the customer", $actor);

        return $holding;
    }

    /**
     * Auto-release every holding whose inspection window has elapsed.
     * Disputed holdings are skipped by the status filter.
     *
     * @return int Number released.
     */
    public function releaseDue(): int
    {
        $due = EscrowHolding::where('status', EscrowHolding::STATUS_PENDING_RELEASE)
            ->whereNotNull('releasable_at')
            ->where('releasable_at', '<=', now())
            ->get();

        foreach ($due as $holding) {
            $this->release($holding, 'auto');
        }

        return $due->count();
    }

    /**
     * What a seller is owed and what is still being held for them.
     *
     * @return array<string, float|int>
     */
    public function balanceFor(int $sellerId): array
    {
        $base = fn () => EscrowHolding::where('seller_id', $sellerId);

        return [
            'availableBalance' => round((float) (clone $base())->awaitingPayout()->sum('net_amount'), 2),
            'heldBalance' => round((float) (clone $base())->stillHeld()->sum('net_amount'), 2),
            'disputedBalance' => round((float) (clone $base())->where('status', EscrowHolding::STATUS_DISPUTED)->sum('net_amount'), 2),
            'paidOut' => round((float) (clone $base())->where('status', EscrowHolding::STATUS_PAID)->sum('net_amount'), 2),
            'commissionPaid' => round((float) (clone $base())->whereIn('status', [EscrowHolding::STATUS_RELEASED, EscrowHolding::STATUS_PAID])->sum('commission_amount'), 2),
            'awaitingPayoutCount' => (clone $base())->awaitingPayout()->count(),
        ];
    }

    /**
     * Bundle everything a seller is currently owed into one payout record for
     * an administrator to settle.
     */
    public function createPayout(int $sellerId, User $actor, ?string $method = null, ?string $destination = null, ?string $notes = null): Payout
    {
        return DB::transaction(function () use ($sellerId, $actor, $method, $destination, $notes): Payout {
            $holdings = EscrowHolding::where('seller_id', $sellerId)->awaitingPayout()->lockForUpdate()->get();

            if ($holdings->isEmpty()) {
                throw new RuntimeException('This seller has no released funds awaiting payout.');
            }

            $payout = Payout::create([
                'reference' => 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                'seller_id' => $sellerId,
                'amount' => round((float) $holdings->sum(fn (EscrowHolding $h) => (float) $h->net_amount), 2),
                'holdings_count' => $holdings->count(),
                'status' => Payout::STATUS_PENDING,
                'method' => $method,
                'destination' => $destination,
                'notes' => $notes,
                'requested_by' => $actor->id,
            ]);

            EscrowHolding::whereIn('id', $holdings->pluck('id'))->update(['payout_id' => $payout->id]);

            $this->audit->record('payout.created', $payout, [
                'amount' => (float) $payout->amount,
                'holdings' => $payout->holdings_count,
            ], "Prepared payout {$payout->reference} of TZS ".number_format((float) $payout->amount, 0), $actor);

            return $payout;
        });
    }

    /** Mark a payout settled after the money has actually been sent. */
    public function markPayoutPaid(Payout $payout, User $actor, ?string $notes = null): Payout
    {
        if ($payout->isPaid()) {
            return $payout;
        }

        DB::transaction(function () use ($payout, $actor, $notes): void {
            $payout->forceFill([
                'status' => Payout::STATUS_PAID,
                'paid_at' => now(),
                'paid_by' => $actor->id,
                'notes' => $notes ?? $payout->notes,
            ])->save();

            $payout->holdings()->update(['status' => EscrowHolding::STATUS_PAID]);
        });

        $this->audit->record('payout.paid', $payout, ['amount' => (float) $payout->amount], "Marked payout {$payout->reference} as paid", $actor);

        return $payout->refresh();
    }

    /** Undo a payout that was never actually sent. */
    public function cancelPayout(Payout $payout, User $actor): Payout
    {
        if ($payout->isPaid()) {
            throw new RuntimeException('A payout that has been paid cannot be cancelled.');
        }

        DB::transaction(function () use ($payout): void {
            // Put the money back on the seller's available balance.
            $payout->holdings()->update(['payout_id' => null]);
            $payout->forceFill(['status' => Payout::STATUS_CANCELLED, 'holdings_count' => 0])->save();
        });

        $this->audit->record('payout.cancelled', $payout, [], "Cancelled payout {$payout->reference}", $actor);

        return $payout->refresh();
    }
}
