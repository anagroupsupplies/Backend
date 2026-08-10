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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $data = ['productsCount' => Product::count(), 'categoriesCount' => Category::count(), 'ordersCount' => Order::count(), 'pendingOrdersCount' => Order::where('status', 'pending')->count(), 'deliveredOrdersCount' => Order::where('status', 'delivered')->count(), 'revenue' => (float) Order::where('status', 'delivered')->sum('total'), 'shopsCount' => Shop::count(), 'activeShopsCount' => Shop::where('is_active', true)->count()];
        if ($request->user()->isMaster()) {
            $data['usersCount'] = User::count();
            $data['adminsCount'] = User::whereIn('role', ['admin', 'master'])->count();
            $data['sellersCount'] = User::where('role', 'seller')->count();
            $data['buyersCount'] = User::where('role', 'user')->count();
            $data['activeSellersCount'] = User::where('role', 'seller')->where('is_active', true)->count();
            $data['inactiveSellersCount'] = User::where('role', 'seller')->where('is_active', false)->count();
            $data['analyticsEventsCount'] = AnalyticsEvent::count();
        }

        return response()->json(['data' => $data]);
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
        } $user->update($data);

        return response()->json(['message' => 'User updated.']);
    }

    public function destroyUser(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->is($user), 422, 'You cannot delete your own account.');
        $user->delete();

        return response()->json([], 204);
    }

    public function orders(): JsonResponse
    {
        return response()->json(['data' => Order::with(['items', 'user'])->latest()->get()]);
    }

    public function updateOrder(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:pending,confirmed,processing,shipped,delivered,cancelled']]);
        $order->update($data);

        return response()->json(['data' => $order]);
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

        return response()->json(['data' => [
            'productsCount' => Product::where('seller_id', $sellerId)->count(),
            'activeProductsCount' => Product::where('seller_id', $sellerId)->where('is_active', true)->count(),
            'lowStockProductsCount' => Product::where('seller_id', $sellerId)->where('stock', '<=', 5)->count(),
            'ordersCount' => (clone $items)->distinct('order_id')->count('order_id'),
            'sales' => (float) (clone $items)->sum(DB::raw('unit_price * quantity')),
            'earnings' => (float) (clone $items)->sum(DB::raw('unit_price * quantity')),
            'shop' => $request->user()->shop,
        ]]);
    }

    public function sellerOrders(Request $request): JsonResponse
    {
        $orders = Order::with(['user', 'items' => fn ($query) => $query->where('seller_id', $request->user()->id)])
            ->whereHas('items', fn ($query) => $query->where('seller_id', $request->user()->id))
            ->latest()
            ->get();

        return response()->json(['data' => $orders]);
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
        ]);
        if (array_key_exists('isActive', $data)) {
            $data['is_active'] = $data['isActive'];
            unset($data['isActive']);
        }
        $shop->update($data);

        return response()->json(['data' => $shop]);
    }

    public function reviews(): JsonResponse
    {
        return response()->json(['data' => Review::with(['product', 'user'])->latest()->get()]);
    }

    public function destroyReview(Review $review): JsonResponse
    {
        $review->delete();

        return response()->json([], 204);
    }
}
