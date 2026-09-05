<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => CartItem::with('product')->where('user_id', $request->user()->id)->get()->map(fn (CartItem $item) => $this->data($item))]);
    }

    public function store(Request $request): JsonResponse
    {
        $productId = $request->input('productId') ?? $request->input('product_id');
        abort_if(! $productId, 422, 'The product id is required.');

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'selectedSize' => ['nullable', 'string', 'max:100'],
            'selected_size' => ['nullable', 'string', 'max:100'],
        ]);

        $product = Product::where('is_active', true)
            ->where(function ($q) use ($productId) {
                if (is_numeric($productId)) {
                    $q->where('id', (int) $productId)->orWhere('slug', (string) $productId);
                } else {
                    $q->where('slug', (string) $productId);
                }
            })
            ->first();

        abort_if(! $product, 422, 'The selected product is invalid.');

        $size = $data['selectedSize'] ?? $data['selected_size'] ?? 'none';
        $item = CartItem::firstOrNew(['user_id' => $request->user()->id, 'product_id' => $product->id, 'selected_size' => $size]);
        $item->quantity = min($product->stock, ($item->exists ? $item->quantity : 0) + $data['quantity']);
        abort_if($item->quantity < 1, 422, 'This product is out of stock.');
        $item->save();

        return response()->json(['data' => $this->data($item->load('product'))], 201);
    }

    public function update(Request $request, CartItem $cart): JsonResponse
    {
        abort_unless($cart->user_id === $request->user()->id, 404);
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1']]);
        abort_if($data['quantity'] > $cart->product()->value('stock'), 422, 'Requested quantity is not available.');
        $cart->update($data);

        return response()->json(['data' => $this->data($cart->load('product'))]);
    }

    public function destroy(Request $request, CartItem $cart): JsonResponse
    {
        abort_unless($cart->user_id === $request->user()->id, 404);
        $cart->delete();

        return response()->json([], 204);
    }

    /** @return array<string, mixed> */
    private function data(CartItem $item): array
    {
        $product = $item->product;
        if (! $product) {
            return [
                'id' => (string) $item->id,
                'productId' => (string) $item->product_id,
                'name' => 'Product Unavailable',
                'price' => 0.0,
                'quantity' => $item->quantity,
                'image' => null,
                'selectedSize' => $item->selected_size,
                'sizingType' => 'none',
                'stock' => 0,
            ];
        }

        return [
            'id' => (string) $item->id,
            'productId' => (string) $product->id,
            'name' => $product->name,
            'price' => (float) $product->price,
            'quantity' => $item->quantity,
            'image' => $product->image,
            'selectedSize' => $item->selected_size,
            'sizingType' => $product->sizing_type,
            'stock' => $product->stock,
        ];
    }
}
