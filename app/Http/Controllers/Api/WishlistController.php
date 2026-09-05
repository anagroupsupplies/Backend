<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => WishlistItem::with('product')
                ->where('user_id', $request->user()->id)
                ->latest()
                ->get()
                ->map(fn (WishlistItem $item) => $this->data($item)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $productId = $request->input('productId') ?? $request->input('product_id');
        abort_if(! $productId, 422, 'The product id is required.');

        $product = Product::where(function ($q) use ($productId) {
            if (is_numeric($productId)) {
                $q->where('id', (int) $productId)->orWhere('slug', (string) $productId);
            } else {
                $q->where('slug', (string) $productId);
            }
        })->first();

        abort_if(! $product, 422, 'The selected product is invalid.');

        $item = WishlistItem::firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);

        return response()->json(['data' => $this->data($item->load('product'))], 201);
    }

    public function destroy(Request $request, string $wishlist): JsonResponse
    {
        $item = WishlistItem::where('user_id', $request->user()->id)
            ->where(function ($q) use ($wishlist) {
                if (is_numeric($wishlist)) {
                    $q->where('id', (int) $wishlist)->orWhere('product_id', (int) $wishlist);
                } else {
                    $q->whereHas('product', fn ($pq) => $pq->where('slug', $wishlist));
                }
            })
            ->first();

        abort_unless($item, 404, 'Wishlist item not found.');
        $item->delete();

        return response()->json([], 204);
    }

    public function moveToCart(Request $request, string $wishlistItem): JsonResponse
    {
        $item = WishlistItem::where('user_id', $request->user()->id)
            ->where(function ($q) use ($wishlistItem) {
                if (is_numeric($wishlistItem)) {
                    $q->where('id', (int) $wishlistItem)->orWhere('product_id', (int) $wishlistItem);
                } else {
                    $q->whereHas('product', fn ($pq) => $pq->where('slug', $wishlistItem));
                }
            })
            ->first();

        abort_unless($item, 404, 'Wishlist item not found.');

        CartItem::updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $item->product_id, 'selected_size' => 'none'],
            ['quantity' => 1]
        );
        $item->delete();

        return response()->json(['message' => 'Moved to cart.']);
    }

    /** @return array<string, mixed> */
    private function data(WishlistItem $item): array
    {
        $product = $item->product;
        if (! $product) {
            return [
                'id' => (string) $item->id,
                'productId' => (string) $item->product_id,
                'name' => 'Product Unavailable',
                'price' => 0.0,
                'image' => null,
                'description' => null,
            ];
        }

        return [
            'id' => (string) $item->id,
            'productId' => (string) $product->id,
            'name' => $product->name,
            'price' => (float) $product->price,
            'image' => $product->image,
            'description' => $product->description,
        ];
    }
}
