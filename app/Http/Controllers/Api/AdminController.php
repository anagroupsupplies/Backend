<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $data = ['productsCount' => Product::count(), 'categoriesCount' => Category::count(), 'ordersCount' => Order::count(), 'pendingOrdersCount' => Order::where('status', 'pending')->count(), 'deliveredOrdersCount' => Order::where('status', 'delivered')->count(), 'revenue' => (float) Order::where('status', 'delivered')->sum('total')];
        if ($request->user()->isMaster()) {
            $data['usersCount'] = User::count();
            $data['adminsCount'] = User::whereIn('role', ['admin', 'master'])->count();
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
        $data = $request->validate(['role' => ['sometimes', 'in:user,admin,master'], 'isActive' => ['sometimes', 'boolean']]);
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
}
