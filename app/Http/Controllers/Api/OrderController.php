<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
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
            $cart = CartItem::where('user_id', $request->user()->id)->lockForUpdate()->get();
            abort_if($cart->isEmpty(), 422, 'Cart is empty.');
            $products = Product::whereIn('id', $cart->pluck('product_id')->unique())->lockForUpdate()->get()->keyBy('id');
            foreach ($cart as $item) {
                $product = $products->get($item->product_id);
                abort_if(! $product || ! $product->is_active || $item->quantity > $product->stock, 422, ($product?->name ?? 'An item').' is out of stock or does not have the requested quantity available.');
                $item->setRelation('product', $product);
            }
            $subtotal = $cart->sum(fn (CartItem $item) => (float) $item->product->price * $item->quantity);
            $order = Order::create(['number' => 'ANA-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)), 'user_id' => $request->user()->id, 'subtotal' => $subtotal, 'shipping_total' => 0, 'total' => $subtotal, 'status' => 'pending', 'shipping_details' => $shipping]);
            foreach ($cart as $item) {
                $product = $item->product;
                $order->items()->create(['product_id' => $product->id, 'seller_id' => $product->seller_id, 'shop_id' => $product->shop_id, 'name' => $product->name, 'unit_price' => $product->price, 'quantity' => $item->quantity, 'selected_size' => $item->selected_size, 'sizing_type' => $product->sizing_type, 'image' => $product->image, 'product_snapshot' => ['id' => $product->id, 'sellerId' => $product->seller_id, 'shopId' => $product->shop_id, 'name' => $product->name, 'price' => $product->price]]);
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
        return ['id' => (string) $order->id, 'number' => $order->number, 'userId' => (string) $order->user_id, 'items' => $order->items->map(fn ($item) => ['id' => (string) $item->id, 'productId' => $item->product_id ? (string) $item->product_id : null, 'sellerId' => $item->seller_id ? (string) $item->seller_id : null, 'shopId' => $item->shop_id ? (string) $item->shop_id : null, 'name' => $item->name, 'price' => (float) $item->unit_price, 'quantity' => $item->quantity, 'selectedSize' => $item->selected_size, 'sizingType' => $item->sizing_type, 'image' => $item->image]), 'total' => (float) $order->total, 'shippingDetails' => $order->shipping_details, 'status' => $order->status, 'createdAt' => $order->created_at, 'updatedAt' => $order->updated_at];
    }
}
