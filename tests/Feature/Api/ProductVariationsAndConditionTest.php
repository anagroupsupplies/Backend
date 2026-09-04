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

test('sellers can publish products with condition, condition details, specifications and varieties', function () {
    $seller = User::factory()->create(['role' => 'seller']);
    $cat = Category::create(['name' => 'Electronics', 'slug' => 'electronics']);

    $response = $this->actingAs($seller)->postJson('/api/v1/seller/products', [
        'name' => 'iPhone 13 Pro',
        'categoryId' => $cat->id,
        'price' => 1500000,
        'stock' => 5,
        'condition' => 'used_good',
        'conditionDetails' => 'Light scratches on bezel, 89% battery health.',
        'specifications' => [
            ['name' => 'Brand', 'value' => 'Apple'],
            ['name' => 'Model', 'value' => 'iPhone 13 Pro'],
            ['name' => 'Storage', 'value' => '256GB'],
        ],
        'variants' => [
            ['name' => '128GB - Graphite', 'price' => 1400000, 'stock' => 2, 'sku' => 'IP13-128-GR'],
            ['name' => '256GB - Sierra Blue', 'price' => 1550000, 'stock' => 3, 'sku' => 'IP13-256-SB'],
        ],
    ])->assertCreated();

    $data = $response->json('data');
    expect($data['condition'])->toBe('used_good')
        ->and($data['conditionDetails'])->toBe('Light scratches on bezel, 89% battery health.')
        ->and($data['specifications'])->toHaveCount(3)
        ->and($data['variants'])->toHaveCount(2)
        ->and($data['variants'][0]['name'])->toBe('128GB - Graphite');

    $productId = $data['id'];

    // Can fetch single product with varieties and specifications
    $single = $this->getJson("/api/v1/products/{$data['slug']}")->assertOk()->json('data');
    expect($single['condition'])->toBe('used_good')
        ->and($single['variants'])->toHaveCount(2);

    // Can update condition and varieties
    $this->actingAs($seller)->patchJson("/api/v1/seller/products/{$productId}", [
        'condition' => 'refurbished',
        'conditionDetails' => 'Certified refurbished by Apple.',
    ])->assertOk();

    $updated = $this->getJson("/api/v1/products/{$data['slug']}")->assertOk()->json('data');
    expect($updated['condition'])->toBe('refurbished')
        ->and($updated['conditionDetails'])->toBe('Certified refurbished by Apple.');
});

test('public products list and seller list can filter by product condition', function () {
    $seller = User::factory()->create(['role' => 'seller']);
    $cat = Category::create(['name' => 'Tech', 'slug' => 'tech']);

    Product::create(['seller_id' => $seller->id, 'category_id' => $cat->id, 'name' => 'Brand New Camera', 'slug' => 'cam-new', 'price' => 900000, 'stock' => 10, 'condition' => 'new', 'is_active' => true]);
    Product::create(['seller_id' => $seller->id, 'category_id' => $cat->id, 'name' => 'Used Camera Good', 'slug' => 'cam-used', 'price' => 450000, 'stock' => 2, 'condition' => 'used_good', 'is_active' => true]);
    Product::create(['seller_id' => $seller->id, 'category_id' => $cat->id, 'name' => 'Refurbished Lens', 'slug' => 'lens-refurb', 'price' => 300000, 'stock' => 4, 'condition' => 'refurbished', 'is_active' => true]);

    // Filter by new
    $newProducts = $this->getJson('/api/v1/products?condition=new')->assertOk()->json('data');
    expect($newProducts)->toHaveCount(1)
        ->and($newProducts[0]['name'])->toBe('Brand New Camera');

    // Filter by used (should match used_good as well as refurbished)
    $usedProducts = $this->getJson('/api/v1/products?condition=used')->assertOk()->json('data');
    expect($usedProducts)->toHaveCount(2);

    // Seller can filter by condition in manageable products
    $sellerNew = $this->actingAs($seller)->getJson('/api/v1/seller/products?condition=new')->assertOk()->json('data');
    expect($sellerNew)->toHaveCount(1)
        ->and($sellerNew[0]['name'])->toBe('Brand New Camera');
});
