<?php

use App\Http\Middleware\AuthenticateFirebase;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(AuthenticateFirebase::class);
    $this->seller = User::factory()->create(['role' => 'seller']);
    $this->shop = Shop::create([
        'seller_id' => $this->seller->id,
        'name' => 'Safari Electronics',
        'slug' => 'safari-electronics',
        'is_active' => true,
    ]);
    $this->category = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    $this->product = Product::create([
        'seller_id' => $this->seller->id,
        'category_id' => $this->category->id,
        'name' => 'Wireless Noise Cancelling Earbuds',
        'slug' => 'earbuds-anc',
        'price' => 65000,
        'stock' => 12,
        'is_active' => true,
        'description' => 'Great wireless earbuds with active noise cancellation and long battery life.',
    ]);
});

test('seller can retrieve store ai analytics and health audit', function () {
    $response = $this->actingAs($this->seller)->getJson('/api/v1/seller/ai/analytics');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'healthScore',
            'healthStatus',
            'summary',
            'vitals' => [
                'catalog' => ['total', 'active', 'lowStock', 'outOfStock', 'thinDescription'],
                'orders' => ['total', 'pending', 'fulfillmentRate'],
                'sales' => ['grossSales', 'pendingSales', 'averageOrderValue'],
                'support' => ['openTickets'],
            ],
            'topProducts',
            'checks',
        ],
    ]);
});

test('seller can retrieve tailored ai recommendations', function () {
    $response = $this->actingAs($this->seller)->getJson('/api/v1/seller/ai/recommendations');

    $response->assertOk();
    $response->assertJsonStructure(['data']);
});

test('seller can generate product listing with ai copilot', function () {
    $response = $this->actingAs($this->seller)->postJson('/api/v1/seller/ai/generate-product', [
        'name' => 'Smart Fitness Watch',
        'category' => 'Wearables',
        'condition' => 'new',
        'features' => 'Heart rate sensor, 7-day battery, IP68 water resistant',
        'approximatePrice' => 85000,
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'title',
            'description',
            'specifications',
            'suggestedPricing' => ['minimum', 'recommended', 'maximum'],
            'tags',
        ],
    ]);
});

test('seller can optimize product pricing with ai pricing advisor', function () {
    $response = $this->actingAs($this->seller)->postJson('/api/v1/seller/ai/optimize-pricing', [
        'currentPrice' => 50000,
        'costPrice' => 35000,
        'category' => 'Tech',
        'stock' => 15,
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'currentPrice',
            'costPrice',
            'marginPercent',
            'recommendedDiscountPercent',
            'recommendedDiscountPrice',
            'pricingTactic',
            'bundleSuggestion',
        ],
    ]);
});

test('seller can chat with context-aware store business advisor', function () {
    $response = $this->actingAs($this->seller)->postJson('/api/v1/seller/ai/chat', [
        'message' => 'How can I increase my sales this month?',
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => ['reply'],
    ]);
});

test('seller can request ai drafted reply for customer ticket', function () {
    $customer = User::factory()->create();
    $ticket = Ticket::create([
        'user_id' => $customer->id,
        'reference' => 'TCK-12345',
        'seller_id' => $this->seller->id,
        'subject' => 'Delivery status inquiry',
        'category' => 'delivery',
        'status' => 'open',
    ]);

    $response = $this->actingAs($this->seller)->getJson("/api/v1/seller/ai/ticket-reply/{$ticket->id}");

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => ['suggestion'],
    ]);
});
