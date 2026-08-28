<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shop;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EscrowService;
use App\Services\OrderNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly EscrowService $escrow,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $isMaster = $request->user()->isMaster();
        $cacheKey = $isMaster ? 'dashboard_master' : 'dashboard_admin';

        return response()->json(['data' => Cache::remember($cacheKey, 30, function () use ($isMaster) {
            $data = ['productsCount' => Product::count(), 'categoriesCount' => Category::count(), 'ordersCount' => Order::count(), 'pendingOrdersCount' => Order::where('status', 'pending')->count(), 'deliveredOrdersCount' => Order::where('status', 'delivered')->count(), 'revenue' => (float) Order::paid()->sum('total'), 'pendingRevenue' => (float) Order::whereNot('payment_status', Order::PAY_STATUS_PAID)->whereNot('status', 'cancelled')->sum('total'), 'paidOrdersCount' => Order::paid()->count(), 'awaitingPaymentCount' => Order::where('payment_status', Order::PAY_STATUS_PROCESSING)->count(), 'shopsCount' => Shop::count(), 'activeShopsCount' => Shop::where('is_active', true)->count()];
            if ($isMaster) {
                $data['usersCount'] = User::count();
                $data['adminsCount'] = User::whereIn('role', ['admin', 'master'])->count();
                $data['sellersCount'] = User::where('role', 'seller')->count();
                $data['buyersCount'] = User::where('role', 'user')->count();
                $data['activeSellersCount'] = User::where('role', 'seller')->where('is_active', true)->count();
                $data['inactiveSellersCount'] = User::where('role', 'seller')->where('is_active', false)->count();
                $data['analyticsEventsCount'] = AnalyticsEvent::count();
            }

            return $data;
        })]);
    }

    public function users(): JsonResponse
    {
        return response()->json(['data' => User::latest()->get()->map(fn (User $user) => ['id' => (string) $user->id, 'uid' => $user->firebase_uid, 'displayName' => $user->name, 'email' => $user->email, 'photoURL' => $user->photo_url, 'role' => $user->role, 'isAdmin' => $user->isAdmin(), 'isMaster' => $user->isMaster(), 'isActive' => $user->is_active, 'createdAt' => $user->created_at])]);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->is($user) && ($request->input('role') !== null && $request->input('role') !== 'master' || $request->input('isActive') === false), 422, 'You cannot remove your own master access.');
        $data = $request->validate(['role' => ['sometimes', 'in:user,buyer,seller,admin,master,main_admin'], 'isActive' => ['sometimes', 'boolean']]);
        if (($data['role'] ?? null) === 'buyer') {
            $data['role'] = 'user';
        } elseif (($data['role'] ?? null) === 'main_admin') {
            $data['role'] = 'master';
        }
        if (array_key_exists('isActive', $data)) {
            $data['is_active'] = $data['isActive'];
            unset($data['isActive']);
        }
        $before = ['role' => $user->role, 'is_active' => $user->is_active];
        $user->update($data);
        $changes = $this->audit->diff($before, ['role' => $user->role, 'is_active' => $user->is_active]);

        if (array_key_exists('role', $changes)) {
            $this->audit->record('user.role_changed', $user, ['role' => $changes['role']], "Changed {$user->email} from {$changes['role']['from']} to {$changes['role']['to']}");
        }
        if (array_key_exists('is_active', $changes)) {
            $this->audit->record('user.status_changed', $user, ['is_active' => $changes['is_active']], ($user->is_active ? 'Activated ' : 'Suspended ').$user->email);
        }

        return response()->json(['message' => 'User updated.']);
    }

    public function destroyUser(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->is($user), 422, 'You cannot delete your own account.');
        // Recorded before deletion so the trail keeps the details of the
        // account that no longer exists.
        $this->audit->record('user.deleted', $user, ['role' => $user->role, 'email' => $user->email], "Deleted the account {$user->email}");
        $user->delete();

        return response()->json([], 204);
    }

    public function orders(): JsonResponse
    {
        return response()->json(['data' => Order::with(['items', 'user'])->latest()->get()]);
    }

    public function updateOrder(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Order::STATUSES)]]);
        $previousStatus = $order->status;
        $changed = $previousStatus !== $data['status'];
        $order->update($data);
        // An admin override is authoritative, so every line follows it.
        $order->items()->update(['fulfillment_status' => $data['status'], 'fulfillment_updated_at' => now()]);

        // Cash is collected at the door, so a delivered cash order is the point
        // at which the money actually exists and may count towards revenue.
        if ($data['status'] === 'delivered' && $order->isPaidOnDelivery() && ! $order->isPaid()) {
            $order->markPaid((float) $order->total, channel: 'Cash on delivery');
        }

        if ($changed) {
            $this->audit->record('order.status_changed', $order, ['status' => ['from' => $previousStatus, 'to' => $data['status']]], "Order {$order->number}: {$previousStatus} → {$data['status']}");
            $this->notifier->statusUpdated($order->refresh(), $data['status']);
        }

        return response()->json(['data' => $order->fresh()]);
    }

    public function track(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string', 'max:100'], 'data' => ['nullable', 'array']]);
        AnalyticsEvent::create(['user_id' => $request->user()->id, ...$data]);

        return response()->json([], 202);
    }

    public function sellerDashboard(Request $request): JsonResponse
    {
        $sellerId = $request->user()->id;
        $items = OrderItem::where('seller_id', $sellerId);
        $paidItems = (clone $items)->whereHas('order', fn ($query) => $query->paid());
        $unpaidItems = (clone $items)->whereHas('order', fn ($query) => $query->whereNot('payment_status', Order::PAY_STATUS_PAID)->whereNot('status', 'cancelled'));

        return response()->json(['data' => [
            'productsCount' => Product::where('seller_id', $sellerId)->count(),
            'activeProductsCount' => Product::where('seller_id', $sellerId)->where('is_active', true)->count(),
            'lowStockProductsCount' => Product::where('seller_id', $sellerId)->where('stock', '<=', 5)->count(),
            'ordersCount' => (clone $items)->distinct('order_id')->count('order_id'),
            // Only settled money counts as sales/earnings; unpaid orders are reported separately.
            'sales' => (float) (clone $paidItems)->sum(DB::raw('unit_price * quantity')),
            'earnings' => (float) (clone $paidItems)->sum(DB::raw('unit_price * quantity')),
            'pendingEarnings' => (float) $unpaidItems->sum(DB::raw('unit_price * quantity')),
            'shop' => $request->user()->shop,
        ]]);
    }

    public function sellerOrders(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'payment' => ['nullable', Rule::in(['paid', 'unpaid', 'processing', 'failed'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'highest', 'lowest'])],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $sellerId = $request->user()->id;
        $mine = fn ($query) => $query->where('seller_id', $sellerId);

        $query = Order::with(['user', 'items' => $mine])->whereHas('items', $mine);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($outer) use ($search, $sellerId): void {
                $outer->where('number', 'like', "%{$search}%")
                    ->orWhere('shipping_details', 'like', "%{$search}%")
                    ->orWhere('payment_reference', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('items', fn ($i) => $i->where('seller_id', $sellerId)->where('name', 'like', "%{$search}%"));
            });
        }

        // Filter on the seller's own lines, not the derived order status, so a
        // seller sees their own progress rather than another shop's hold-up.
        if ($status = $filters['status'] ?? null) {
            $query->whereHas('items', fn ($i) => $i->where('seller_id', $sellerId)->where('fulfillment_status', $status));
        }

        match ($filters['payment'] ?? null) {
            'paid' => $query->where('payment_status', Order::PAY_STATUS_PAID),
            'unpaid' => $query->whereNot('payment_status', Order::PAY_STATUS_PAID),
            'processing' => $query->where('payment_status', Order::PAY_STATUS_PROCESSING),
            'failed' => $query->where('payment_status', Order::PAY_STATUS_FAILED),
            default => null,
        };

        if ($from = $filters['from'] ?? null) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $filters['to'] ?? null) {
            $query->whereDate('created_at', '<=', $to);
        }

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->oldest(),
            'highest' => $query->orderByDesc('total'),
            'lowest' => $query->orderBy('total'),
            default => $query->latest(),
        };

        $orders = $query->paginate($filters['perPage'] ?? 15)->withQueryString();

        return response()->json(['data' => [
            'orders' => $orders->items(),
            'meta' => ['total' => $orders->total(), 'perPage' => $orders->perPage(), 'currentPage' => $orders->currentPage(), 'lastPage' => $orders->lastPage()],
            'summary' => $this->sellerOrderSummary($sellerId),
        ]]);
    }

    /**
     * Counts per fulfilment status across this seller's own order lines.
     *
     * @return array<string, int>
     */
    private function sellerOrderSummary(int $sellerId): array
    {
        $counts = OrderItem::where('seller_id', $sellerId)
            ->select('fulfillment_status', DB::raw('COUNT(DISTINCT order_id) as total'))
            ->groupBy('fulfillment_status')
            ->pluck('total', 'fulfillment_status');

        $summary = ['all' => OrderItem::where('seller_id', $sellerId)->distinct('order_id')->count('order_id')];
        foreach (Order::STATUSES as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }

        return $summary;
    }

    /**
     * A seller advances only their own lines on an order. The order status is
     * then recomputed from every seller's lines.
     */
    public function updateSellerOrderStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Order::SELLER_STATUSES)]]);
        $sellerId = $request->user()->id;
        $isAdmin = $request->user()->isAdmin();

        $lines = $order->items()->when(! $isAdmin, fn ($query) => $query->where('seller_id', $sellerId));
        abort_unless((clone $lines)->exists(), 404);

        $changed = (clone $lines)->whereNot('fulfillment_status', $data['status'])->count() > 0;
        (clone $lines)->update(['fulfillment_status' => $data['status'], 'fulfillment_updated_at' => now()]);

        $order->syncStatusFromItems();
        $order->refresh();

        if ($data['status'] === 'delivered' && $order->status === 'delivered' && $order->isPaidOnDelivery() && ! $order->isPaid()) {
            $order->markPaid((float) $order->total, channel: 'Cash on delivery');
            $order->refresh();
        }

        // Delivering starts this shop's escrow inspection window. An admin
        // acting on the whole order starts it for every shop on it.
        if ($data['status'] === 'delivered') {
            foreach ($isAdmin ? $order->items()->whereNotNull('seller_id')->distinct()->pluck('seller_id') : [$sellerId] as $id) {
                $this->escrow->markDelivered($order, (int) $id);
            }
        }

        if ($changed) {
            $this->audit->record('order.status_changed', $order, ['status' => ['to' => $data['status']], 'scope' => $isAdmin ? 'all items' : 'own items'], "Order {$order->number}: marked {$data['status']}");
            $this->notifier->statusUpdated($order, $data['status'], $isAdmin ? null : $request->user()->shop?->name);
        }

        return response()->json(['data' => $order->load(['user', 'items' => fn ($query) => $isAdmin ? $query : $query->where('seller_id', $sellerId)])]);
    }

    /**
     * A single order, restricted to the lines that belong to this seller. An
     * order containing no line of theirs is a 404, so sellers cannot read
     * another shop's order by guessing the id.
     */
    public function sellerOrder(Request $request, Order $order): JsonResponse
    {
        $sellerId = $request->user()->id;

        if (! $request->user()->isAdmin()) {
            abort_unless($order->items()->where('seller_id', $sellerId)->exists(), 404);
            $order->load(['user', 'items' => fn ($query) => $query->where('seller_id', $sellerId)]);
        } else {
            $order->load(['user', 'items']);
        }

        return response()->json(['data' => $order]);
    }

    public function shops(): JsonResponse
    {
        return response()->json(['data' => Shop::with('seller')->latest()->get()]);
    }

    public function updateShop(Request $request, Shop $shop): JsonResponse
    {
        $data = $request->validate([
            'isActive' => ['sometimes', 'boolean'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'string'],
            'banner' => ['nullable', 'string'],
            'primaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accentColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        if (array_key_exists('isActive', $data)) {
            $data['is_active'] = $data['isActive'];
            unset($data['isActive']);
        }
        $settings = $shop->settings ?? [];
        foreach (['primaryColor', 'accentColor'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = $data[$key];
                unset($data[$key]);
            }
        }
        $wasActive = $shop->is_active;
        $shop->update([...$data, 'settings' => $settings]);

        if (array_key_exists('is_active', $data) && $wasActive !== $shop->is_active) {
            $this->audit->record('shop.status_changed', $shop, ['is_active' => ['from' => $wasActive, 'to' => $shop->is_active]], ($shop->is_active ? 'Activated ' : 'Suspended ')."the shop {$shop->name}");
        }

        return response()->json(['data' => $shop]);
    }

    public function reviews(): JsonResponse
    {
        return response()->json(['data' => Review::with(['product', 'user'])->latest()->get()]);
    }

    public function destroyReview(Review $review): JsonResponse
    {
        $this->audit->record('review.deleted', $review, ['rating' => $review->rating, 'comment' => $review->comment], "Deleted a review on product #{$review->product_id}");
        $review->delete();

        return response()->json([], 204);
    }
}
