<?php

namespace App\Services;

use App\Models\Category;
use App\Models\EscrowHolding;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\Shop;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Throwable;

class AiIntelligenceService
{
    public function __construct(
        protected DeepSeekService $deepSeek
    ) {}

    /**
     * Compute comprehensive platform health audit.
     *
     * @return array<string, mixed>
     */
    public function systemHealth(): array
    {
        $productsTotal = Product::count();
        $productsActive = Product::where('is_active', true)->count();
        $productsOutOfStock = Product::where('stock', '<=', 0)->count();
        $productsLowStock = Product::where('stock', '>', 0)->where('stock', '<=', 5)->count();
        $productsUncategorized = Product::whereNull('category_id')->count();
        $productsThin = Product::whereNull('description')
            ->orWhereRaw('LENGTH(description) < 40')
            ->count();
        $productsNoImage = Product::whereNull('image')->orWhere('image', '')->count();

        $ordersTotal = Order::count();
        $ordersPending = Order::where('status', 'pending')->count();
        $ordersDelayedPending = Order::where('status', 'pending')
            ->where('created_at', '<', now()->subHours(48))
            ->count();
        $ordersShipped = Order::where('status', 'shipped')->count();
        $ordersDelivered = Order::where('status', 'delivered')->count();
        $ordersCancelled = Order::where('status', 'cancelled')->count();

        $escrowDisputed = EscrowHolding::where('status', 'disputed')->count();
        $escrowHeld = EscrowHolding::where('status', 'held')->count();
        $escrowReadyPayout = EscrowHolding::where('status', 'released')->whereNull('payout_id')->count();

        $pendingSellerApps = SellerApplication::where('status', 'pending')->count();
        $activeShops = Shop::where('is_active', true)->count();
        $inactiveShops = Shop::where('is_active', false)->count();

        $openTickets = Ticket::where('status', 'open')->count();

        // Calculate weighted score starting at 100
        $score = 100;
        $score -= min(25, $escrowDisputed * 8); // Frozen disputes heavily penalized
        $score -= min(20, $ordersDelayedPending * 5); // Delayed pending orders
        $score -= min(15, $openTickets * 3); // Unanswered tickets
        $score -= min(15, $pendingSellerApps * 2); // Unreviewed seller applications

        if ($productsTotal > 0) {
            $outOfStockRatio = $productsOutOfStock / $productsTotal;
            if ($outOfStockRatio > 0.25) {
                $score -= 10;
            } elseif ($outOfStockRatio > 0.10) {
                $score -= 5;
            }

            $uncategorizedRatio = $productsUncategorized / $productsTotal;
            if ($uncategorizedRatio > 0.20) {
                $score -= 5;
            }
        }

        $score = max(25, min(100, (int) round($score)));

        $status = match (true) {
            $score >= 90 => 'excellent',
            $score >= 75 => 'good',
            $score >= 60 => 'needs_attention',
            default => 'critical',
        };

        $checks = [
            [
                'name' => 'Catalog Stock Availability',
                'status' => $productsOutOfStock === 0 ? 'pass' : ($productsOutOfStock > 5 ? 'warn' : 'info'),
                'detail' => $productsOutOfStock === 0
                    ? 'All catalog items have stock available.'
                    : "{$productsOutOfStock} product(s) currently out of stock; {$productsLowStock} with critical stock (≤5).",
                'action' => 'View out of stock items in Catalog',
            ],
            [
                'name' => 'Order Fulfillment Velocity',
                'status' => $ordersDelayedPending === 0 ? 'pass' : 'warn',
                'detail' => $ordersDelayedPending === 0
                    ? 'No orders are pending fulfillment over 48 hours.'
                    : "{$ordersDelayedPending} order(s) pending fulfillment for over 48 hours.",
                'action' => 'Check orders pipeline',
            ],
            [
                'name' => 'Escrow & Dispute Safety',
                'status' => $escrowDisputed === 0 ? 'pass' : 'fail',
                'detail' => $escrowDisputed === 0
                    ? 'No open escrow disputes. Buyer protection is clear.'
                    : "{$escrowDisputed} holding(s) in active dispute requiring administrator settlement.",
                'action' => 'Resolve disputed escrow holdings',
            ],
            [
                'name' => 'Seller Onboarding Pipeline',
                'status' => $pendingSellerApps === 0 ? 'pass' : 'warn',
                'detail' => $pendingSellerApps === 0
                    ? 'All seller applications have been reviewed.'
                    : "{$pendingSellerApps} seller application(s) awaiting compliance and identity review.",
                'action' => 'Review seller applications',
            ],
            [
                'name' => 'Customer Support Response',
                'status' => $openTickets === 0 ? 'pass' : 'warn',
                'detail' => $openTickets === 0
                    ? 'Zero unanswered customer support tickets.'
                    : "{$openTickets} customer ticket(s) awaiting shop or administrator reply.",
                'action' => 'Open support inbox',
            ],
            [
                'name' => 'Catalog SEO & Data Richness',
                'status' => ($productsUncategorized === 0 && $productsThin === 0) ? 'pass' : 'info',
                'detail' => ($productsUncategorized === 0 && $productsThin === 0)
                    ? 'Catalog items are categorized with rich descriptions.'
                    : "{$productsUncategorized} uncategorized item(s); {$productsThin} item(s) with short descriptions.",
                'action' => 'Use AI Description Generator to enrich catalog',
            ],
        ];

        return [
            'score' => $score,
            'status' => $status,
            'summary' => match ($status) {
                'excellent' => 'System vitals are healthy. Catalog, escrow safety, and support queues are performing well.',
                'good' => 'Operations are running smoothly with minor items requiring periodic administrative maintenance.',
                'needs_attention' => 'Action required on open tickets, delayed order fulfillment, or pending applications.',
                default => 'Urgent administrative intervention required for disputes or blocked operational pipelines.',
            },
            'timestamp' => now()->toIso8601String(),
            'breakdown' => [
                'catalog' => [
                    'total' => $productsTotal,
                    'active' => $productsActive,
                    'outOfStock' => $productsOutOfStock,
                    'lowStock' => $productsLowStock,
                    'uncategorized' => $productsUncategorized,
                    'thinDescription' => $productsThin,
                    'missingImage' => $productsNoImage,
                    'healthPercent' => $productsTotal > 0 ? round((($productsActive - $productsOutOfStock) / $productsTotal) * 100, 1) : 100,
                ],
                'orders' => [
                    'total' => $ordersTotal,
                    'pending' => $ordersPending,
                    'delayedPending' => $ordersDelayedPending,
                    'shipped' => $ordersShipped,
                    'delivered' => $ordersDelivered,
                    'cancelled' => $ordersCancelled,
                    'fulfillmentRate' => $ordersTotal > 0 ? round(($ordersDelivered / $ordersTotal) * 100, 1) : 100,
                ],
                'escrow' => [
                    'disputed' => $escrowDisputed,
                    'held' => $escrowHeld,
                    'readyPayout' => $escrowReadyPayout,
                ],
                'sellers' => [
                    'activeShops' => $activeShops,
                    'inactiveShops' => $inactiveShops,
                    'pendingApplications' => $pendingSellerApps,
                ],
                'support' => [
                    'openTickets' => $openTickets,
                ],
            ],
            'checks' => $checks,
        ];
    }

    /**
     * Generate dynamic AI business recommendations based on real store state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recommendations(): array
    {
        $recommendations = [];

        // 1. Escrow disputes (Critical priority)
        $disputes = EscrowHolding::with(['order', 'seller'])->where('status', 'disputed')->limit(3)->get();
        if ($disputes->isNotEmpty()) {
            $recommendations[] = [
                'id' => 'rec-escrow-disputes',
                'type' => 'escrow',
                'priority' => 'critical',
                'impact' => 'Customer Trust',
                'title' => 'Settle Pending Escrow Disputes',
                'message' => "There are {$disputes->count()} order payment holding(s) marked as disputed. Review buyer evidence and release funds or issue refunds to protect platform reputation.",
                'actionLabel' => 'Go to Escrow & Payouts',
                'actionTab' => 'escrow',
            ];
        }

        // 2. Pending Seller Applications (High priority)
        $pendingApps = SellerApplication::where('status', 'pending')->count();
        if ($pendingApps > 0) {
            $recommendations[] = [
                'id' => 'rec-seller-apps',
                'type' => 'sellers',
                'priority' => 'high',
                'impact' => 'Catalog Growth',
                'title' => "Review {$pendingApps} Pending Seller Application".($pendingApps > 1 ? 's' : ''),
                'message' => "New merchants have submitted business documents and NIDA scans. Approving verified sellers expands platform inventory and transaction volume.",
                'actionLabel' => 'Review Applications',
                'actionTab' => 'applications',
            ];
        }

        // 3. Low stock / Out of stock warnings
        $outOfStock = Product::where('stock', '<=', 0)->where('is_active', true)->limit(5)->get(['id', 'name', 'price']);
        if ($outOfStock->isNotEmpty()) {
            $names = $outOfStock->pluck('name')->slice(0, 3)->implode(', ');
            $recommendations[] = [
                'id' => 'rec-inventory-out',
                'type' => 'inventory',
                'priority' => 'high',
                'impact' => 'Lost Revenue Prevention',
                'title' => 'Restock Alert for Active Products',
                'message' => "The following published items are out of stock: {$names}. Request stock replenishment from corresponding sellers or temporarily set items inactive to prevent customer frustration.",
                'actionLabel' => 'Moderate Products',
                'actionTab' => 'products',
            ];
        }

        // 4. Products with high rating or stock that are not featured
        $candidateFeatured = Product::where('featured', false)
            ->where('is_active', true)
            ->where('stock', '>', 5)
            ->whereNotNull('image')
            ->orderByDesc('price')
            ->first();

        if ($candidateFeatured) {
            $recommendations[] = [
                'id' => "rec-feature-{$candidateFeatured->id}",
                'type' => 'marketing',
                'priority' => 'medium',
                'impact' => 'Conversion Rate',
                'title' => "Feature \"{$candidateFeatured->name}\" on Homepage",
                'message' => "This product has verified stock and attractive imagery, but is currently not featured on the homepage carousel. Featuring it can increase discovery and immediate sales.",
                'actionLabel' => 'Feature Now',
                'actionTab' => 'products',
                'productId' => $candidateFeatured->id,
                'canOneClickFeature' => true,
            ];
        }

        // 5. Open customer tickets
        $openTickets = Ticket::where('status', 'open')->count();
        if ($openTickets > 0) {
            $recommendations[] = [
                'id' => 'rec-tickets-open',
                'type' => 'support',
                'priority' => 'medium',
                'impact' => 'Customer Retention',
                'title' => "Address {$openTickets} Unanswered Support Ticket".($openTickets > 1 ? 's' : ''),
                'message' => 'Shoppers have active inquiries waiting for response. Rapid customer response within 2 hours boosts repeat purchase rate by over 30%.',
                'actionLabel' => 'Open Support Tickets',
                'actionTab' => 'tickets',
            ];
        }

        // 6. Products with thin descriptions
        $thinProduct = Product::where(function ($q) {
            $q->whereNull('description')->orWhereRaw('LENGTH(description) < 40');
        })->where('is_active', true)->first();

        if ($thinProduct) {
            $recommendations[] = [
                'id' => 'rec-catalog-seo',
                'type' => 'catalog',
                'priority' => 'low',
                'impact' => 'Search & SEO',
                'title' => 'Enrich Thin Product Descriptions',
                'message' => "Products like \"{$thinProduct->name}\" have minimal descriptions. Use the AI Content Studio to generate comprehensive specifications and dual-language highlights.",
                'actionLabel' => 'Launch AI Studio',
                'actionTab' => 'ai',
            ];
        }

        // 7. Uncategorized products
        $uncategorizedCount = Product::whereNull('category_id')->count();
        if ($uncategorizedCount > 0) {
            $recommendations[] = [
                'id' => 'rec-uncategorized',
                'type' => 'catalog',
                'priority' => 'medium',
                'impact' => 'Discovery',
                'title' => "Assign Categories to {$uncategorizedCount} Uncategorized Items",
                'message' => 'Items without categories do not appear in category filters or navigation badges on the customer storefront.',
                'actionLabel' => 'Edit in Products',
                'actionTab' => 'products',
            ];
        }

        return $recommendations;
    }

    /**
     * Synthesize high-converting, dual-language product description and SEO tags.
     *
     * @param  array<string, mixed>  $input
     */
    public function generateProductDescription(array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'General'));
        $condition = trim((string) ($input['condition'] ?? 'New'));
        $features = trim((string) ($input['features'] ?? ''));
        $targetAudience = trim((string) ($input['targetAudience'] ?? ''));

        // If DeepSeek LLM is configured, ask for intelligent copy
        $apiKey = (string) config('services.deepseek.api_key');
        if ($apiKey !== '') {
            try {
                $prompt = <<<PROMPT
Create a high-converting, professional e-commerce product description for the following product:
- Name: {$name}
- Category: {$category}
- Condition: {$condition}
- Key Highlights/Features: {$features}
- Audience: {$targetAudience}

Format the output cleanly:
1. An engaging 2-sentence opening hook.
2. "Key Highlights / Sifa Kuu:" followed by 4-5 concise bullet points.
3. A brief section in Swahili: "Maelezo kwa Ufupi (Swahili)".
4. "Condition / Hali ya Bidhaa:" indicating {$condition} with quality guarantee.
5. "SEO Keywords / Maneno Muhimu:" 5-6 comma-separated tags.
Do not include chat greetings or markdown code fences.
PROMPT;

                $response = Http::baseUrl(rtrim((string) config('services.deepseek.base_url'), '/'))
                    ->withToken($apiKey)
                    ->acceptJson()
                    ->timeout(20)
                    ->post('/chat/completions', [
                        'model' => config('services.deepseek.model'),
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are an expert e-commerce copywriter specializing in Tanzanian online retail (English & Swahili).'],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'temperature' => 0.6,
                        'max_tokens' => 600,
                    ]);

                if ($response->successful()) {
                    $text = (string) $response->json('choices.0.message.content');
                    if (trim($text) !== '') {
                        if ($name !== '' && ! str_contains($text, $name)) {
                            $text = "**{$name}**\n\n".$text;
                        }
                        return trim($text);
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // High quality fallback template generator
        $bulletPoints = $features !== ''
            ? array_filter(array_map('trim', explode(',', $features)))
            : [
                'Premium build quality designed for longevity and daily use',
                '100% authentic item inspected for flawless condition',
                'Compatible with standard use and backed by seller warranty',
                'Fast and insured doorstep delivery across Tanzania via verified carriers',
            ];

        $bulletsFormatted = implode("\n", array_map(fn ($b) => "• {$b}", $bulletPoints));

        $swahiliCondition = strtolower($condition) === 'used'
            ? 'Iliyotumika lakini ipo kwenye hali nzuri sana (Imekaguliwa)'
            : 'Mpya kabisa (Brand New), haijatumika';

        return <<<TEXT
Discover the premium {$name}, designed to deliver exceptional performance, comfort, and style in the {$category} collection. Whether for daily lifestyle or professional utility, this item offers reliability and modern craftsmanship.

✨ Key Highlights / Sifa Kuu:
{$bulletsFormatted}

🔍 Condition / Hali:
{$condition} ({$swahiliCondition}). Guaranteed genuine item verified by Antenkayume quality standards.

🇹🇿 Maelezo kwa Kiswahili:
Bidhaa hii ya {$name} inakuletea ubora wa kipekee kwa bei nafuu. Inafaa sana kwa matumizi ya kila siku na inapatikana ikiwa na ulinzi kamili wa malipo (Escrow Protection) ili uhakikishiwe kupokea ulichoagiza.

🏷️ Search Tags:
{$name}, {$category}, {$condition}, online shopping Tanzania, Antenkayume
TEXT;
    }

    /**
     * Suggest an empathetic, definitive ticket resolution message for administrators.
     */
    public function suggestTicketResolution(Ticket $ticket): string
    {
        $customerName = $ticket->user?->name ?? 'Valued Customer';
        $shopName = $ticket->shop?->name ?? 'the seller';
        $subject = $ticket->subject;

        $lastMessage = $ticket->messages()->latest('id')->first()?->body ?? '';

        return <<<TEXT
Dear {$customerName},

Thank you for reaching out to Antenkayume Support regarding "{$subject}". We take every transaction seriously and our moderation team has reviewed your case.

We have contacted {$shopName} to verify fulfillment and order tracking details. Please rest assured that your payment is held securely in our Escrow system and will not be finalized until you confirm delivery satisfaction.

If you have already received the item or need a replacement/refund, please reply directly to this message so we can finalize the appropriate resolution immediately.

Warm regards,
Antenkayume Customer Care & Operations Team
TEXT;
    }

    /**
     * Compute real-time analytics velocity and business trends.
     *
     * @return array<string, mixed>
     */
    public function analyticsTrends(): array
    {
        $recentDays = 7;
        $currentPeriodRevenue = (float) Order::where('payment_status', 'paid')
            ->where('created_at', '>=', now()->subDays($recentDays))
            ->sum('total');

        $previousPeriodRevenue = (float) Order::where('payment_status', 'paid')
            ->whereBetween('created_at', [now()->subDays($recentDays * 2), now()->subDays($recentDays)])
            ->sum('total');

        $growthRate = $previousPeriodRevenue > 0
            ? round((($currentPeriodRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100, 1)
            : ($currentPeriodRevenue > 0 ? 100.0 : 0.0);

        // Top categories by active product count
        $categories = Category::withCount('products')
            ->orderByDesc('products_count')
            ->limit(5)
            ->get(['id', 'name', 'slug', 'icon'])
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'productsCount' => $c->products_count,
            ]);

        // Top shops by product inventory
        $shops = Shop::withCount('products')
            ->where('is_active', true)
            ->orderByDesc('products_count')
            ->limit(5)
            ->get(['id', 'name', 'slug'])
            ->map(fn (Shop $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'productsCount' => $s->products_count,
            ]);

        $insights = [
            $growthRate >= 0
                ? "Revenue trend is positive with {$growthRate}% growth over the past 7 days."
                : "Weekly revenue shifted by {$growthRate}%. Recommend running seasonal homepage banner promotions.",
            'Escrow protection maintains 100% fund safety before seller delivery confirmation.',
            'Expanding high-performing categories will attract new merchant applications.',
        ];

        return [
            'recentRevenue' => $currentPeriodRevenue,
            'previousRevenue' => $previousPeriodRevenue,
            'growthPercent' => $growthRate,
            'topCategories' => $categories,
            'topShops' => $shops,
            'insights' => $insights,
        ];
    }
}
