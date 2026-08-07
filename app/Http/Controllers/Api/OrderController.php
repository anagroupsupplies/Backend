<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Order::with('items')->where('user_id', $request->user()->id)->latest()->get()->map(fn (Order $order) => $this->data($order))]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id || $request->user()->isAdmin(), 404);

        return response()->json(['data' => $this->data($order->load('items'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $shipping = $request->validate(['shippingDetails' => ['required', 'array'], 'shippingDetails.fullName' => ['required', 'string', 'max:255'], 'shippingDetails.email' => ['required', 'email'], 'shippingDetails.phone' => ['required', 'string', 'max:50'], 'shippingDetails.streetAddress' => ['required', 'string', 'max:255'], 'shippingDetails.city' => ['required', 'string', 'max:100'], 'shippingDetails.state' => ['required', 'string', 'max:100'], 'shippingDetails.postalCode' => ['required', 'string', 'max:30'], 'shippingDetails.country' => ['required', 'string', 'max:100']])['shippingDetails'];
        $order = DB::transaction(function () use ($request, $shipping): Order {
            $cart = CartItem::with('product')->where('user_id', $request->user()->id)->lockForUpdate()->get();
            abort_if($cart->isEmpty(), 422, 'Cart is empty.');
            foreach ($cart as $item) {
                abort_if(! $item->product->is_active || $item->quantity > $item->product->stock, 422, "{$item->product->name} is no longer available in the requested quantity.");
            }
            $subtotal = $cart->sum(fn (CartItem $item) => (float) $item->product->price * $item->quantity);
            $order = Order::create(['number' => 'ANA-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)), 'user_id' => $request->user()->id, 'subtotal' => $subtotal, 'shipping_total' => 0, 'total' => $subtotal, 'status' => 'pending', 'shipping_details' => $shipping]);
            foreach ($cart as $item) {
                $product = $item->product;
                $order->items()->create(['product_id' => $product->id, 'name' => $product->name, 'unit_price' => $product->price, 'quantity' => $item->quantity, 'selected_size' => $item->selected_size, 'sizing_type' => $product->sizing_type, 'image' => $product->image, 'product_snapshot' => ['id' => $product->id, 'name' => $product->name, 'price' => $product->price]]);
                $product->decrement('stock', $item->quantity);
            }
            CartItem::where('user_id', $request->user()->id)->delete();

            return $order->load('items');
        });

        return response()->json(['data' => $this->data($order)], 201);
    }

    /** @return array<string, mixed> */
    private function data(Order $order): array
    {
        return ['id' => (string) $order->id, 'number' => $order->number, 'userId' => (string) $order->user_id, 'items' => $order->items->map(fn ($item) => ['id' => (string) $item->id, 'productId' => $item->product_id ? (string) $item->product_id : null, 'name' => $item->name, 'price' => (float) $item->unit_price, 'quantity' => $item->quantity, 'selectedSize' => $item->selected_size, 'sizingType' => $item->sizing_type, 'image' => $item->image]), 'total' => (float) $order->total, 'shippingDetails' => $order->shipping_details, 'status' => $order->status, 'createdAt' => $order->created_at, 'updatedAt' => $order->updated_at];
    }
}
