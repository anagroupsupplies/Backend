<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        return response()->json(['data' => Review::with('user')->where('product_id', $product->id)->latest()->get()->map(fn (Review $review) => $this->data($review))]);
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['required', 'string', 'max:3000']]);
        $review = Review::updateOrCreate(['product_id' => $product->id, 'user_id' => $request->user()->id], $data);

        return response()->json(['data' => $this->data($review->load('user'))], 201);
    }

    /** @return array<string, mixed> */
    private function data(Review $review): array
    {
        return ['id' => (string) $review->id, 'productId' => (string) $review->product_id, 'userId' => (string) $review->user_id, 'userName' => $review->user->name, 'userPhoto' => $review->user->photo_url, 'rating' => $review->rating, 'comment' => $review->comment, 'createdAt' => $review->created_at];
    }
}
