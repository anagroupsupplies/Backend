<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Ticket;
use App\Services\AiIntelligenceService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiIntelligenceController extends Controller
{
    public function __construct(
        protected AiIntelligenceService $ai,
        protected AuditLogger $audit
    ) {}

    /**
     * Complete system health and diagnostic audit.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'data' => $this->ai->systemHealth(),
        ]);
    }

    /**
     * AI-generated business recommendations.
     */
    public function recommendations(): JsonResponse
    {
        return response()->json([
            'data' => $this->ai->recommendations(),
        ]);
    }

    /**
     * Analytics velocity and trends summary.
     */
    public function analytics(): JsonResponse
    {
        return response()->json([
            'data' => $this->ai->analyticsTrends(),
        ]);
    }

    /**
     * Generate rich e-commerce product description.
     */
    public function generateDescription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'string', 'in:New,Used,new,used'],
            'features' => ['nullable', 'string', 'max:1000'],
            'targetAudience' => ['nullable', 'string', 'max:255'],
        ]);

        $description = $this->ai->generateProductDescription($validated);

        return response()->json([
            'data' => [
                'description' => $description,
            ],
        ]);
    }

    /**
     * Suggest empathetic, professional ticket resolution for administrators.
     */
    public function suggestTicketReply(Ticket $ticket): JsonResponse
    {
        $suggestion = $this->ai->suggestTicketResolution($ticket);

        return response()->json([
            'data' => [
                'suggestion' => $suggestion,
            ],
        ]);
    }

    /**
     * Quick-action trigger: feature an item directly from AI recommendation.
     */
    public function featureProduct(Request $request, Product $product): JsonResponse
    {
        $oldFeatured = $product->featured;
        $product->update(['featured' => true]);

        $this->audit->record(
            'product.updated',
            $product,
            ['featured' => ['from' => $oldFeatured, 'to' => true]],
            "AI Assistant: Featured product {$product->name} on homepage"
        );

        return response()->json([
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'featured' => true,
            ],
        ]);
    }
}
