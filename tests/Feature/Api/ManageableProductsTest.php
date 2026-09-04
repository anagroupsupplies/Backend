<?php

use App\Http\Middleware\AuthenticateFirebase;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(AuthenticateFirebase::class);
});

test('manageable products supports searching, category, status and sorting filters', function () {
    $seller = User::factory()->create(['role' => 'seller']);
    $cat = Category::create(['name' => 'Tech', 'slug' => 'tech']);
    $p1 = Product::create(['seller_id' => $seller->id, 'category_id' => $cat->id, 'name' => 'High Stock Phone', 'slug' => 'phone-1', 'price' => 100000, 'stock' => 50, 'is_active' => true]);
    $p2 = Product::create(['seller_id' => $seller->id, 'category_id' => $cat->id, 'name' => 'Low Stock Phone', 'slug' => 'phone-2', 'price' => 50000, 'stock' => 2, 'is_active' => true]);
    $p3 = Product::create(['seller_id' => $seller->id, 'category_id' => $cat->id, 'name' => 'Draft Laptop', 'slug' => 'laptop-1', 'price' => 200000, 'stock' => 0, 'is_active' => false]);

    $all = $this->actingAs($seller)->getJson('/api/v1/seller/products')->assertOk()->json('data');
    expect($all)->toHaveCount(3);

    $lowStock = $this->actingAs($seller)->getJson('/api/v1/seller/products?status=low_stock')->assertOk()->json('data');
    expect($lowStock)->toHaveCount(2)->and(collect($lowStock)->pluck('id'))->toContain((string) $p2->id, (string) $p3->id);

    $activeOnly = $this->actingAs($seller)->getJson('/api/v1/seller/products?status=active')->assertOk()->json('data');
    expect($activeOnly)->toHaveCount(2);

    $inactiveOnly = $this->actingAs($seller)->getJson('/api/v1/seller/products?status=inactive')->assertOk()->json('data');
    expect($inactiveOnly)->toHaveCount(1)->and($inactiveOnly[0]['id'])->toBe((string) $p3->id);

    $searchMatch = $this->actingAs($seller)->getJson('/api/v1/seller/products?search=Draft')->assertOk()->json('data');
    expect($searchMatch)->toHaveCount(1)->and($searchMatch[0]['id'])->toBe((string) $p3->id);

    $sortedByPrice = $this->actingAs($seller)->getJson('/api/v1/seller/products?sort=price_asc')->assertOk()->json('data');
    expect($sortedByPrice[0]['id'])->toBe((string) $p2->id);
});

test('catalog cache versioning invalidates public product list when products are modified or stock changes', function () {
    $seller = User::factory()->create(['role' => 'seller']);
    $product = Product::create(['seller_id' => $seller->id, 'name' => 'Atomic Gadget', 'slug' => 'atomic-gadget', 'price' => 15000, 'stock' => 10, 'is_active' => true]);

    $initial = $this->getJson('/api/v1/products')->assertOk()->json('data');
    expect(collect($initial)->pluck('name'))->toContain('Atomic Gadget');

    // Updating the product bakes in the change and invalidates the cache immediately
    $this->actingAs($seller)->patchJson("/api/v1/seller/products/{$product->id}", ['name' => 'Atomic Super Gadget'])->assertOk();

    $updated = $this->getJson('/api/v1/products')->assertOk()->json('data');
    expect(collect($updated)->pluck('name'))->toContain('Atomic Super Gadget');
});
