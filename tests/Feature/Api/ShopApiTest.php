<?php

use App\Http\Middleware\AuthenticateFirebase;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(AuthenticateFirebase::class);
});

test('catalog endpoints expose active products in the frontend shape', function () {
    $category = Category::create(['name' => 'Shoes', 'slug' => 'shoes']);
    $product = Product::create(['category_id' => $category->id, 'name' => 'Running Shoe', 'slug' => 'running-shoe', 'price' => 45000, 'stock' => 5]);

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0.id', (string) $product->id)
        ->assertJsonPath('data.0.category', 'Shoes')
        ->assertJsonPath('data.0.price', 45000);
});

test('checkout calculates totals from mysql, snapshots items, decrements stock, and clears the cart', function () {
    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey', 'price' => 50000, 'stock' => 4]);
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'L', 'quantity' => 2]);
    $this->actingAs($user);

    $response = $this->postJson('/api/v1/checkout', ['shippingDetails' => [
        'fullName' => 'Test Customer', 'email' => 'customer@example.com', 'phone' => '255700000000',
        'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
        'postalCode' => '11101', 'country' => 'Tanzania',
    ]]);

    $response->assertCreated()->assertJsonPath('data.total', 100000)->assertJsonPath('data.items.0.selectedSize', 'L');
    expect(Order::first()->items)->toHaveCount(1)
        ->and($product->fresh()->stock)->toBe(2)
        ->and(CartItem::count())->toBe(0);
});

test('a customer cannot access administrator routes', function () {
    $this->actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

test('a normal administrator can manage shop operations but not master resources', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);

    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonMissingPath('data.usersCount');
    $this->getJson('/api/v1/admin/orders')->assertOk();
    $this->getJson('/api/v1/admin/users')->assertForbidden();
    $this->putJson('/api/v1/admin/settings', ['whatsappNumber' => '255700000000'])->assertForbidden();
});

test('a master administrator receives full metrics and controls users', function () {
    $master = User::factory()->create(['role' => 'master']);
    $this->actingAs($master);

    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.usersCount', 1);
    $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonPath('data.0.role', 'master');
});

test('a seller cannot reassign product ownership or self-feature via update', function () {
    $sellerA = User::factory()->create(['role' => 'seller']);
    $sellerB = User::factory()->create(['role' => 'seller']);
    $product = Product::create(['seller_id' => $sellerA->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 20000, 'stock' => 3]);

    $this->actingAs($sellerA);
    $this->patchJson("/api/v1/seller/products/{$product->id}", [
        'sellerId' => $sellerB->id,
        'featured' => true,
    ])->assertOk();

    expect($product->fresh())
        ->seller_id->toBe($sellerA->id)
        ->featured->toBeFalse();
});

test('an administrator can reassign product ownership and featured status via update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $seller = User::factory()->create(['role' => 'seller']);
    $product = Product::create(['seller_id' => $seller->id, 'name' => 'Bag', 'slug' => 'bag-admin', 'price' => 20000, 'stock' => 3]);

    $this->actingAs($admin);
    $this->patchJson("/api/v1/admin/products/{$product->id}", [
        'featured' => true,
    ])->assertOk();

    expect($product->fresh()->featured)->toBeTrue();
});
