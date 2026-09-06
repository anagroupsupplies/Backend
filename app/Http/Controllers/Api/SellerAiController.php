<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\SellerAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerAiController extends Controller
{
    public function __construct(
        protected SellerAiService $ai
    ) {}

    /**
     * Seller store health audit, inventory velocity and fulfillment analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->ai->analytics($request->user()),
        ]);
    }

    /**
     * Dynamic actionable AI recommendations tailored for the seller.
     */
    public function recommendations(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->ai->recommendations($request->user()),
        ]);
    }

    /**
     * AI Product Copilot: Generates optimized title, markdown description, specifications, and pricing.
     */
    public function generateProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'string', 'max:50'],
            'features' => ['nullable', 'string', 'max:1000'],
            'targetAudience' => ['nullable', 'string', 'max:255'],
            'approximatePrice' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->ai->generateProductListing($validated, $request->user());

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Pricing and margin optimization analysis.
     */
    public function optimizePricing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currentPrice' => ['required', 'numeric', 'min:0'],
            'costPrice' => ['nullable', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:100'],
            'stock' => ['nullable', 'integer', 'min:0'],
        ]);

        $result = $this->ai->optimizePricing($validated);

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Context-aware interactive AI business advisor chat for the seller.
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array', 'max:10'],
            'history.*.type' => ['required_with:history', 'string', 'in:user,assistant,bot'],
            'history.*.message' => ['required_with:history', 'string', 'max:2000'],
        ]);

        $reply = $this->ai->chat(
            $validated['message'],
            $validated['history'] ?? [],
            $request->user()
        );

        return response()->json([
            'data' => [
                'reply' => $reply,
            ],
        ]);
    }

    /**
     * Suggest polite, professional customer service resolution for a support ticket.
     */
    public function suggestTicketReply(Request $request, Ticket $ticket): JsonResponse
    {
        $sellerId = $request->user()->id;

        // Ensure ticket belongs to seller or an order containing their items
        $allowed = $ticket->seller_id === $sellerId ||
            $ticket->order?->items()->where('seller_id', $sellerId)->exists() ||
            $request->user()->isAdmin();

        abort_unless($allowed, 403, 'Unauthorized ticket access.');

        $suggestion = $this->ai->suggestTicketReply($ticket, $request->user());

        return response()->json([
            'data' => [
                'suggestion' => $suggestion,
            ],
        ]);
    }
}
