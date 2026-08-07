<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => WishlistItem::with('product')->where('user_id', $request->user()->id)->get()->map(fn (WishlistItem $item) => $this->data($item))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['productId' => ['required', 'exists:products,id']]);
        $item = WishlistItem::firstOrCreate(['user_id' => $request->user()->id, 'product_id' => $data['productId']]);

        return response()->json(['data' => $this->data($item->load('product'))], 201);
    }

    public function destroy(Request $request, WishlistItem $wishlist): JsonResponse
    {
        abort_unless($wishlist->user_id === $request->user()->id, 404);
        $wishlist->delete();

        return response()->json([], 204);
    }

    public function moveToCart(Request $request, WishlistItem $wishlistItem): JsonResponse
    {
        abort_unless($wishlistItem->user_id === $request->user()->id, 404);
        CartItem::updateOrCreate(['user_id' => $request->user()->id, 'product_id' => $wishlistItem->product_id, 'selected_size' => 'none'], ['quantity' => 1]);
        $wishlistItem->delete();

        return response()->json(['message' => 'Moved to cart.']);
    }

    /** @return array<string, mixed> */
    private function data(WishlistItem $item): array
    {
        $product = $item->product;

        return ['id' => (string) $item->id, 'productId' => (string) $product->id, 'name' => $product->name, 'price' => (float) $product->price, 'image' => $product->image, 'description' => $product->description];
    }
}
