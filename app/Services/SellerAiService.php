<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class SellerAiService
{
    public function __construct(
        protected DeepSeekService $deepSeek
    ) {}

    /**
     * Compute comprehensive store analytics and intelligence for a specific seller.
     *
     * @return array<string, mixed>
     */
    public function analytics(User $seller): array
    {
        $sellerId = $seller->id;

        // Catalog statistics
        $productsTotal = Product::where('seller_id', $sellerId)->count();
        $productsActive = Product::where('seller_id', $sellerId)->where('is_active', true)->count();
        $productsOutOfStock = Product::where('seller_id', $sellerId)->where('stock', '<=', 0)->count();
        $productsLowStock = Product::where('seller_id', $sellerId)->where('stock', '>', 0)->where('stock', '<=', 5)->count();
        $productsThin = Product::where('seller_id', $sellerId)
            ->where(function ($q) {
                $q->whereNull('description')->orWhereRaw('LENGTH(description) < 40');
            })->count();
        $productsNoImage = Product::where('seller_id', $sellerId)
            ->where(function ($q) {
                $q->whereNull('image')->orWhere('image', '');
            })->count();

        // Orders & fulfillment statistics
        $items = OrderItem::where('seller_id', $sellerId);
        $totalOrdersCount = (clone $items)->distinct('order_id')->count('order_id');

        $pendingItems = (clone $items)->where('fulfillment_status', 'pending')->count();
        $delayedPendingItems = (clone $items)->where('fulfillment_status', 'pending')
            ->where('created_at', '<', now()->subHours(48))
            ->count();
        $shippedItems = (clone $items)->where('fulfillment_status', 'shipped')->count();
        $deliveredItems = (clone $items)->where('fulfillment_status', 'delivered')->count();
        $cancelledItems = (clone $items)->where('fulfillment_status', 'cancelled')->count();

        // Revenue statistics
        $paidItems = (clone $items)->whereHas('order', fn ($q) => $q->paid());
        $grossSales = (float) (clone $paidItems)->sum(DB::raw('unit_price * quantity'));
        $unpaidItems = (clone $items)->whereHas('order', fn ($q) => $q->whereNot('payment_status', Order::PAY_STATUS_PAID)->whereNot('status', 'cancelled'));
        $pendingSales = (float) (clone $unpaidItems)->sum(DB::raw('unit_price * quantity'));

        $deliveredCount = (clone $items)->where('fulfillment_status', 'delivered')->distinct('order_id')->count('order_id');
        $fulfillmentRate = $totalOrdersCount > 0 ? round(($deliveredCount / $totalOrdersCount) * 100, 1) : 100.0;
        $averageOrderValue = $totalOrdersCount > 0 ? round($grossSales / $totalOrdersCount, 0) : 0;

        // Open customer tickets for this seller
        $openTickets = Ticket::where('status', 'open')
            ->where(function ($q) use ($sellerId) {
                $q->where('seller_id', $sellerId)
                    ->orWhereHas('order.items', fn ($iq) => $iq->where('seller_id', $sellerId));
            })
            ->count();

        // Top 5 selling products for this seller
        $topProducts = OrderItem::where('seller_id', $sellerId)
            ->select('product_id', 'name', DB::raw('SUM(quantity) as total_sold'), DB::raw('SUM(unit_price * quantity) as total_revenue'))
            ->whereNotNull('product_id')
            ->groupBy('product_id', 'name')
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get()
            ->map(fn ($p) => [
                'productId' => (int) $p->product_id,
                'name' => (string) $p->name,
                'unitsSold' => (int) $p->total_sold,
                'revenue' => (float) $p->total_revenue,
            ])
            ->toArray();

        // Compute weighted seller health score (starts at 100)
        $score = 100;
        $score -= min(25, $delayedPendingItems * 10); // Orders delayed > 48h
        $score -= min(20, $openTickets * 5); // Unanswered tickets
        if ($productsTotal > 0) {
            $outOfStockRatio = $productsOutOfStock / $productsTotal;
            if ($outOfStockRatio > 0.25) {
                $score -= 15;
            } elseif ($outOfStockRatio > 0.10) {
                $score -= 8;
            }

            $thinRatio = $productsThin / $productsTotal;
            if ($thinRatio > 0.30) {
                $score -= 10;
            } elseif ($thinRatio > 0.15) {
                $score -= 5;
            }

            if ($productsNoImage > 0) {
                $score -= min(10, $productsNoImage * 3);
            }
        }

        $score = max(25, min(100, (int) round($score)));

        $healthStatus = match (true) {
            $score >= 90 => 'optimal',
            $score >= 75 => 'good',
            $score >= 60 => 'needs_attention',
            default => 'critical',
        };

        $summary = match ($healthStatus) {
            'optimal' => 'Your store vitals are outstanding. Inventory levels and fulfillment velocity are operating smoothly.',
            'good' => 'Store performance is solid with minor opportunities to enrich descriptions and replenish stock.',
            'needs_attention' => 'Immediate actions needed: address delayed order fulfillment or restock out-of-stock items.',
            default => 'Attention required: multiple fulfillment delays or inventory issues need immediate resolution.',
        };

        $diagnosticChecks = [
            [
                'title' => 'Order Fulfillment Velocity',
                'status' => $delayedPendingItems === 0 ? 'pass' : 'warn',
                'description' => $delayedPendingItems === 0
                    ? 'All active orders are dispatched within reasonable delivery windows.'
                    : "{$delayedPendingItems} order item(s) pending fulfillment for over 48 hours. Dispatch them quickly to maintain customer trust.",
                'action' => 'Go to Orders',
                'tab' => 'orders',
            ],
            [
                'title' => 'Stock Availability & Restock Risk',
                'status' => $productsOutOfStock === 0 ? ($productsLowStock > 0 ? 'info' : 'pass') : 'warn',
                'description' => $productsOutOfStock === 0
                    ? ($productsLowStock > 0 ? "{$productsLowStock} item(s) have critical stock (≤5)." : 'All published catalog products have available stock.')
                    : "{$productsOutOfStock} product(s) are currently out of stock; {$productsLowStock} have low stock (≤5).",
                'action' => 'Manage Products',
                'tab' => 'products',
            ],
            [
                'title' => 'Catalog SEO & Description Richness',
                'status' => ($productsThin === 0 && $productsNoImage === 0) ? 'pass' : 'info',
                'description' => ($productsThin === 0 && $productsNoImage === 0)
                    ? 'All products feature rich descriptions and storefront imagery.'
                    : "{$productsThin} item(s) have minimal description; {$productsNoImage} missing primary image.",
                'action' => 'Use AI Copy Studio',
                'tab' => 'ai',
            ],
            [
                'title' => 'Customer Inquiries & Support Tickets',
                'status' => $openTickets === 0 ? 'pass' : 'warn',
                'description' => $openTickets === 0
                    ? 'No unanswered customer support tickets.'
                    : "{$openTickets} customer ticket(s) currently awaiting your response.",
                'action' => 'View Support Tickets',
                'tab' => 'tickets',
            ],
        ];

        return [
            'healthScore' => $score,
            'healthStatus' => $healthStatus,
            'summary' => $summary,
            'timestamp' => now()->toIso8601String(),
            'vitals' => [
                'catalog' => [
                    'total' => $productsTotal,
                    'active' => $productsActive,
                    'outOfStock' => $productsOutOfStock,
                    'lowStock' => $productsLowStock,
                    'thinDescription' => $productsThin,
                    'missingImage' => $productsNoImage,
                    'stockHealthPercent' => $productsTotal > 0 ? round((($productsActive - $productsOutOfStock) / $productsTotal) * 100, 1) : 100.0,
                ],
                'orders' => [
                    'total' => $totalOrdersCount,
                    'pending' => $pendingItems,
                    'delayedPending' => $delayedPendingItems,
                    'shipped' => $shippedItems,
                    'delivered' => $deliveredItems,
                    'cancelled' => $cancelledItems,
                    'fulfillmentRate' => $fulfillmentRate,
                ],
                'sales' => [
                    'grossSales' => $grossSales,
                    'pendingSales' => $pendingSales,
                    'averageOrderValue' => $averageOrderValue,
                ],
                'support' => [
                    'openTickets' => $openTickets,
                ],
            ],
            'topProducts' => $topProducts,
            'checks' => $diagnosticChecks,
        ];
    }

    /**
     * Generate dynamic actionable AI recommendations for this seller.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recommendations(User $seller): array
    {
        $sellerId = $seller->id;
        $recommendations = [];

        // 1. Critical restock recommendation
        $outOfStock = Product::where('seller_id', $sellerId)
            ->where('stock', '<=', 0)
            ->where('is_active', true)
            ->limit(3)
            ->get(['id', 'name', 'price']);

        if ($outOfStock->isNotEmpty()) {
            $names = $outOfStock->pluck('name')->implode(', ');
            $recommendations[] = [
                'id' => 'rec-seller-restock-out',
                'type' => 'inventory',
                'priority' => 'critical',
                'impact' => 'Prevent Lost Sales',
                'title' => 'Restock Active Products Currently at 0 Inventory',
                'message' => "The following published items are out of stock: {$names}. Customers cannot buy them. Update stock quantities or unpublish them to prevent negative buyer reviews.",
                'actionLabel' => 'Update Stock in Products',
                'actionTab' => 'products',
            ];
        }

        // 2. Urgent orders pending fulfillment
        $delayedOrders = OrderItem::where('seller_id', $sellerId)
            ->where('fulfillment_status', 'pending')
            ->where('created_at', '<', now()->subHours(48))
            ->count();

        if ($delayedOrders > 0) {
            $recommendations[] = [
                'id' => 'rec-seller-delayed-orders',
                'type' => 'fulfillment',
                'priority' => 'critical',
                'impact' => 'Fast Payout & Escrow Release',
                'title' => "Dispatch {$delayedOrders} Order Item(s) Pending Over 48 Hours",
                'message' => "Buyers are eagerly anticipating their delivery. Promptly updating order status to 'shipped' accelerates your escrow payout release upon delivery.",
                'actionLabel' => 'Dispatch Orders',
                'actionTab' => 'orders',
            ];
        }

        // 3. Low stock warning (1 to 5 units remaining)
        $lowStock = Product::where('seller_id', $sellerId)
            ->where('stock', '>', 0)
            ->where('stock', '<=', 5)
            ->where('is_active', true)
            ->limit(3)
            ->get(['id', 'name', 'stock']);

        if ($lowStock->isNotEmpty()) {
            $details = $lowStock->map(fn ($p) => "{$p->name} ({$p->stock} left)")->implode(', ');
            $recommendations[] = [
                'id' => 'rec-seller-low-stock',
                'type' => 'inventory',
                'priority' => 'high',
                'impact' => 'Stock Availability',
                'title' => 'Replenish Running-Out Inventory',
                'message' => "Inventory is running low on: {$details}. Plan restock with your suppliers before inventory hits zero.",
                'actionLabel' => 'Review Inventory',
                'actionTab' => 'products',
            ];
        }

        // 4. Products with thin descriptions
        $thinProduct = Product::where('seller_id', $sellerId)
            ->where(function ($q) {
                $q->whereNull('description')->orWhereRaw('LENGTH(description) < 40');
            })
            ->where('is_active', true)
            ->first(['id', 'name']);

        if ($thinProduct) {
            $recommendations[] = [
                'id' => 'rec-seller-thin-desc',
                'type' => 'marketing',
                'priority' => 'medium',
                'impact' => 'Higher Conversion & SEO',
                'title' => "Enrich Description for \"{$thinProduct->name}\"",
                'message' => 'Items with bulleted highlights, dual-language Swahili/English copy, and detailed specifications achieve 40% higher buyer checkout rates.',
                'actionLabel' => 'Open AI Copy Studio',
                'actionTab' => 'ai',
                'productId' => $thinProduct->id,
                'productName' => $thinProduct->name,
            ];
        }

        // 5. Unanswered customer support tickets
        $openTickets = Ticket::where('status', 'open')
            ->where(function ($q) use ($sellerId) {
                $q->where('seller_id', $sellerId)
                    ->orWhereHas('order.items', fn ($iq) => $iq->where('seller_id', $sellerId));
            })
            ->count();

        if ($openTickets > 0) {
            $recommendations[] = [
                'id' => 'rec-seller-tickets',
                'type' => 'support',
                'priority' => 'high',
                'impact' => 'Customer Retention',
                'title' => "Reply to {$openTickets} Customer Support Inquiry".($openTickets > 1 ? 'ies' : ''),
                'message' => 'Speedy replies to customer delivery and product inquiries turn one-time buyers into loyal repeat customers.',
                'actionLabel' => 'Open Support Tickets',
                'actionTab' => 'tickets',
            ];
        }

        // 6. Growth tip: Add video showcase
        $noVideoCount = Product::where('seller_id', $sellerId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('video')->orWhere('video', '');
            })
            ->count();

        if ($noVideoCount > 0) {
            $recommendations[] = [
                'id' => 'rec-seller-video-showcase',
                'type' => 'growth',
                'priority' => 'low',
                'impact' => 'Visual Proof',
                'title' => 'Attach Product Demonstration Videos (Under 50s)',
                'message' => 'Showcasing real item condition and unboxing videos dramatically builds trust for Tanzanian online shoppers.',
                'actionLabel' => 'Add Video to Products',
                'actionTab' => 'products',
            ];
        }

        return $recommendations;
    }

    /**
     * Generate complete e-commerce product listing copy, specifications, and suggested pricing.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function generateProductListing(array $input, User $seller): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'General'));
        $condition = trim((string) ($input['condition'] ?? 'new'));
        $features = trim((string) ($input['features'] ?? ''));
        $targetAudience = trim((string) ($input['targetAudience'] ?? ''));
        $approximatePrice = ! empty($input['approximatePrice']) ? (float) $input['approximatePrice'] : null;

        $apiKey = (string) config('services.deepseek.api_key');

        if ($apiKey !== '') {
            try {
                $prompt = <<<PROMPT
You are an expert e-commerce merchandising assistant for an online marketplace in Tanzania.
Generate a structured JSON product listing based on:
- Product Title/Concept: {$name}
- Category: {$category}
- Condition: {$condition}
- Key Highlights: {$features}
- Target Customer: {$targetAudience}
- Target Price Reference: {$approximatePrice} TZS

Return ONLY a valid JSON object matching this schema without markdown code fences:
{
  "title": "A refined, search-optimized high-converting product title (max 70 chars)",
  "description": "Engaging markdown product description with: 1) Catchy opening hook, 2) 'Key Highlights / Sifa Kuu:' with 4-5 bullet points, 3) 'Maelezo kwa Kiswahili:' a concise Swahili overview, 4) 'Condition / Hali ya Bidhaa:' warranty and authenticity reassurance.",
  "specifications": [
    {"name": "Specification Name", "value": "Specification Detail"}
  ],
  "suggestedPricing": {
    "minimum": 0,
    "recommended": 0,
    "maximum": 0
  },
  "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"]
}
PROMPT;

                $response = Http::baseUrl(rtrim((string) config('services.deepseek.base_url'), '/'))
                    ->withToken($apiKey)
                    ->acceptJson()
                    ->timeout(25)
                    ->post('/chat/completions', [
                        'model' => config('services.deepseek.model'),
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a JSON-only e-commerce merchandising assistant.'],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'temperature' => 0.5,
                        'max_tokens' => 800,
                    ]);

                if ($response->successful()) {
                    $rawText = (string) $response->json('choices.0.message.content');
                    $cleanJson = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($rawText));
                    $decoded = json_decode($cleanJson, true);
                    if (is_array($decoded) && ! empty($decoded['title']) && ! empty($decoded['description'])) {
                        return [
                            'title' => (string) $decoded['title'],
                            'description' => (string) $decoded['description'],
                            'specifications' => is_array($decoded['specifications'] ?? null) ? $decoded['specifications'] : [],
                            'suggestedPricing' => is_array($decoded['suggestedPricing'] ?? null) ? $decoded['suggestedPricing'] : [
                                'minimum' => $approximatePrice ? round($approximatePrice * 0.9) : 20000,
                                'recommended' => $approximatePrice ?: 25000,
                                'maximum' => $approximatePrice ? round($approximatePrice * 1.2) : 35000,
                            ],
                            'tags' => is_array($decoded['tags'] ?? null) ? $decoded['tags'] : [],
                        ];
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Fallback generator when AI API key is unavailable or offline
        $cleanTitle = ucwords($name !== '' ? $name : 'Premium Quality '.$category.' Item');
        $bulletPoints = $features !== ''
            ? array_filter(array_map('trim', explode(',', $features)))
            : [
                'Premium build quality designed for longevity and daily high-frequency use',
                '100% authentic item inspected for quality and reliability',
                'Fast delivery across Dar es Salaam and regions within 24-48 hours',
                'Protected by buyer escrow guarantee with easy return support',
            ];

        $bulletsMarkdown = implode("\n", array_map(fn ($b) => "- **{$b}**", $bulletPoints));
        $conditionLabel = ucfirst($condition);

        $description = <<<MD
Experience exceptional performance and lasting durability with **{$cleanTitle}**. Designed to exceed expectations, this {$category} essential combines sleek aesthetics with reliable functionality.

### Key Highlights / Sifa Kuu:
{$bulletsMarkdown}

### Maelezo kwa Kiswahili:
Bidhaa hii ya **{$cleanTitle}** imetengenezwa kwa viwango vya juu vya ubora. Inafaa sana kwa matumizi ya kila siku na inakupa thamani halisi ya pesa yako. Usafirishaji wa haraka na salama nchi nzima.

### Condition / Hali ya Bidhaa:
- **Status:** {$conditionLabel} Condition
- **Verification:** 100% Quality Inspected
- **Customer Protection:** Covered by Antenkayume Escrow Buyer Guarantee
MD;

        $specs = [
            ['name' => 'Brand / Origin', 'value' => 'Authentic / Original'],
            ['name' => 'Condition', 'value' => $conditionLabel],
            ['name' => 'Category', 'value' => $category],
            ['name' => 'Warranty', 'value' => 'Standard Seller Warranty'],
        ];

        $basePrice = $approximatePrice ?: 35000;

        return [
            'title' => $cleanTitle,
            'description' => $description,
            'specifications' => $specs,
            'suggestedPricing' => [
                'minimum' => round($basePrice * 0.85, -2),
                'recommended' => round($basePrice, -2),
                'maximum' => round($basePrice * 1.25, -2),
            ],
            'tags' => array_values(array_filter([
                strtolower(str_replace(' ', '-', $name)),
                strtolower($category),
                strtolower($condition),
                'tanzania-deals',
                'fast-delivery',
            ])),
        ];
    }

    /**
     * AI Pricing and Discount Advisor for inventory items.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function optimizePricing(array $input): array
    {
        $currentPrice = max(0, (float) ($input['currentPrice'] ?? 0));
        $costPrice = max(0, (float) ($input['costPrice'] ?? ($currentPrice * 0.7)));
        $category = (string) ($input['category'] ?? 'General');
        $stock = (int) ($input['stock'] ?? 1);

        $margin = $currentPrice > 0 ? (($currentPrice - $costPrice) / $currentPrice) * 100 : 0;
        $profit = max(0, $currentPrice - $costPrice);

        $suggestedDiscount = match (true) {
            $stock > 20 => 15,
            $stock > 10 => 10,
            $stock <= 3 => 0,
            default => 5,
        };

        $discountedPrice = $currentPrice > 0 ? round($currentPrice * (1 - ($suggestedDiscount / 100)), -2) : $currentPrice;

        return [
            'currentPrice' => $currentPrice,
            'costPrice' => $costPrice,
            'estimatedProfit' => $profit,
            'marginPercent' => round($margin, 1),
            'recommendedDiscountPercent' => $suggestedDiscount,
            'recommendedDiscountPrice' => $discountedPrice,
            'pricingTactic' => match (true) {
                $stock > 15 => 'Volume Liquidation: Current inventory is high. A 10-15% discount badge will accelerate conversion velocity and free up working capital.',
                $stock <= 3 => 'Scarcity Pricing: Stock is critically low. Maintain full retail price to protect maximum gross profit margins.',
                default => 'Competitive Equilibrium: Your pricing is well balanced for this category. Offer a multi-item bundle (Buy 2 save 8%) to increase Average Order Value.',
            },
            'bundleSuggestion' => 'Offer "Buy 2 for TZS '.number_format(round($currentPrice * 1.85, -2)).'" to increase basket size while maintaining a healthy '.round($margin * 0.85, 0).'% margin.',
        ];
    }

    /**
     * Generate helpful customer service reply for support ticket.
     */
    public function suggestTicketReply(Ticket $ticket, User $seller): string
    {
        $subject = $ticket->subject;
        $customerName = $ticket->user?->name ?? 'Customer';
        $orderNumber = $ticket->order?->number ?? 'your order';

        return <<<TEXT
Habari {$customerName},

Thank you for reaching out to us regarding {$subject} for {$orderNumber}.

We are actively checking your inquiry and are committed to ensuring your experience is completely seamless. If this concerns your order shipment, our dispatch team is tracking your package and we will provide an update right away.

Asante sana kwa subira yako na kwa kuchagua duka letu. Tuko hapa kukuhudumia wakati wote!

Kind regards,
{$seller->shop?->name} Customer Support
TEXT;
    }

    /**
     * Context-aware interactive AI assistant for the seller.
     *
     * @param  array<int, array{type: string, message: string}>  $history
     */
    public function chat(string $message, array $history, User $seller): string
    {
        $apiKey = (string) config('services.deepseek.api_key');

        if ($apiKey === '') {
            return $this->offlineAssistantReply($message, $seller);
        }

        // Gather real context from this seller's shop
        $sellerId = $seller->id;
        $shopName = $seller->shop?->name ?? 'My Shop';
        $productCount = Product::where('seller_id', $sellerId)->count();
        $lowStockCount = Product::where('seller_id', $sellerId)->where('stock', '<=', 5)->count();
        $pendingOrders = OrderItem::where('seller_id', $sellerId)->where('fulfillment_status', 'pending')->count();

        $topItems = Product::where('seller_id', $sellerId)
            ->where('is_active', true)
            ->limit(5)
            ->get(['name', 'price', 'stock'])
            ->map(fn ($p) => "- {$p->name} (TZS ".number_format((float) $p->price).", stock: {$p->stock})")
            ->implode("\n");

        $systemPrompt = <<<PROMPT
You are the dedicated AI Merchant Advisor & Store Copilot for "{$shopName}", a verified vendor on the Antenkayume online marketplace in Tanzania.

SELLER STORE CONTEXT:
- Shop Name: {$shopName}
- Total Catalog Items: {$productCount}
- Low Stock Items (≤5): {$lowStockCount}
- Pending Orders Awaiting Fulfillment: {$pendingOrders}
- Sample Active Inventory:
{$topItems}

YOUR CAPABILITIES:
1. Provide actionable e-commerce advice (increasing conversions, pricing strategies, inventory replenishment, WhatsApp/Instagram marketing captions in Swahili/English).
2. Advise on order dispatching and logistics in Tanzania (boda-boda, bus parcel couriers, city delivery).
3. Draft promotional copy, customer dispute answers, and product descriptions.
4. Quote prices in Tanzanian Shillings (TZS).
5. Format your responses with clean GitHub Markdown (headers, bullet points, bold highlights).
Be encouraging, commercial, practical, and culturally tuned to the Tanzanian market.
PROMPT;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach (array_slice($history, -6) as $item) {
            $messages[] = [
                'role' => ($item['type'] ?? '') === 'user' ? 'user' : 'assistant',
                'content' => $item['message'] ?? '',
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        try {
            $response = Http::baseUrl(rtrim((string) config('services.deepseek.base_url'), '/'))
                ->withToken($apiKey)
                ->acceptJson()
                ->timeout(30)
                ->post('/chat/completions', [
                    'model' => config('services.deepseek.model'),
                    'messages' => $messages,
                    'temperature' => 0.6,
                    'max_tokens' => 800,
                ]);

            if ($response->successful()) {
                $content = (string) $response->json('choices.0.message.content');
                if (trim($content) !== '') {
                    return trim($content);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $this->offlineAssistantReply($message, $seller);
    }

    /**
     * High-quality offline fallback advisor responses.
     */
    private function offlineAssistantReply(string $message, User $seller): string
    {
        $lower = strtolower($message);
        $shopName = $seller->shop?->name ?? 'your shop';

        if (str_contains($lower, 'sales') || str_contains($lower, 'traffic') || str_contains($lower, 'increase')) {
            return <<<MD
### 🚀 4 Proven Ways to Increase Sales for {$shopName}

1. **Leverage WhatsApp Status & Social Channels**
   - Share high-resolution photos and short video demonstrations of your active inventory.
   - Tanzanian buyers love seeing the exact physical item before buying.

2. **Optimize Pricing & Create Bundles**
   - Offer a bundle deal such as *"Buy 2 items and save 10%"* to increase your average checkout basket.

3. **Enrich Product Descriptions with Dual-Language Details**
   - Include both English and Swahili highlights so buyers easily find your products through search.

4. **Fast Dispatch Builds Repeat Loyalty**
   - Fulfill pending orders within 24 hours to gain 5-star seller ratings and priority algorithm placement on Antenkayume!
MD;
        }

        if (str_contains($lower, 'stock') || str_contains($lower, 'inventory') || str_contains($lower, 'restock')) {
            return <<<MD
### 📦 Inventory Optimization Advice for {$shopName}

- **Replenish Low Stock Early:** Items with 5 or fewer units in stock should be reordered before hitting zero to prevent losing page ranking.
- **Unpublish Inactive/Depleted Products:** If an item is permanently discontinued, mark it inactive in your **Products** tab to prevent disappointed buyers.
- **Safety Margin:** Keep at least 3-5 buffer units for your fastest-moving products.
MD;
        }

        if (str_contains($lower, 'caption') || str_contains($lower, 'post') || str_contains($lower, 'instagram') || str_contains($lower, 'marketing')) {
            return <<<MD
### 📱 Ready-to-Post Marketing Caption

**English & Swahili:**
> 🔥 **Mzigo Mpya Umetua!**
> Jipatie bidhaa bora na za uhakika moja kwa moja kutoka **{$shopName}** kupitia **Antenkayume**!
>
> ✅ Ubora wa Hali ya Juu (100% Inspected)
> 🚚 Usafirishaji wa Haraka Nchi Nzima
> 🔒 Malipo Salama kupitia Escrow Guarantee
>
> 🛒 Bonyeza link kwenye bio au weka oda sasa!
> *#TanzaniaShop #OnlineShoppingDar #{$shopName}*
MD;
        }

        return <<<MD
### 💡 Merchant Advisor for {$shopName}

Thank you for your question! Here are recommended next steps:
- Check your **AI Advisor & Tools** tab for real-time inventory health audits and actionable recommendations.
- Keep your fulfillment window within 24 hours for fast escrow fund release.
- Use our **AI Product Copy Studio** to auto-generate SEO titles, specifications, and Swahili descriptions for newly added inventory!
MD;
    }
}
