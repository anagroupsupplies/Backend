<?php

use App\Http\Middleware\AuthenticateFirebase;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(AuthenticateFirebase::class);
    $this->user = User::factory()->create();
    $this->seller = User::factory()->create(['role' => 'seller']);
    $this->category = Category::create(['name' => 'Gadgets', 'slug' => 'gadgets']);
    $this->product = Product::create([
        'seller_id' => $this->seller->id,
        'category_id' => $this->category->id,
        'name' => 'Awesome Gadget',
        'is_active' => true,
        'stock' => 10,
        'price' => 25000,
        'slug' => 'test-awesome-gadget',
    ]);
});

test('user can add to wishlist using numeric id', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/wishlist', [
        'productId' => $this->product->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.productId', (string) $this->product->id);
    expect(WishlistItem::where('user_id', $this->user->id)->where('product_id', $this->product->id)->exists())->toBeTrue();
});

test('user can add to wishlist using slug', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/wishlist', [
        'productId' => 'test-awesome-gadget',
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.productId', (string) $this->product->id);
    expect(WishlistItem::where('user_id', $this->user->id)->where('product_id', $this->product->id)->exists())->toBeTrue();
});

test('user can add to wishlist using snake_case product_id', function () {
    $response = $this->actingAs($this->user)->postJson('/api/v1/wishlist', [
        'product_id' => $this->product->id,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.productId', (string) $this->product->id);
});

test('user can list wishlist items', function () {
    WishlistItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);

    $response = $this->actingAs($this->user)->getJson('/api/v1/wishlist');

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.productId', (string) $this->product->id);
});

test('user can remove from wishlist by item id or product id or slug', function () {
    $item = WishlistItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);

    // Remove by product slug
    $response = $this->actingAs($this->user)->deleteJson('/api/v1/wishlist/' . $this->product->slug);
    $response->assertStatus(204);
    expect(WishlistItem::find($item->id))->toBeNull();

    // Re-create and remove by product id
    $item2 = WishlistItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);
    $response2 = $this->actingAs($this->user)->deleteJson('/api/v1/wishlist/' . $this->product->id);
    $response2->assertStatus(204);
    expect(WishlistItem::find($item2->id))->toBeNull();
});

test('user can move wishlist item to cart', function () {
    $item = WishlistItem::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);

    $response = $this->actingAs($this->user)->postJson('/api/v1/wishlist/' . $this->product->slug . '/move-to-cart');
    $response->assertStatus(200);

    expect(WishlistItem::find($item->id))->toBeNull();
    $this->assertDatabaseHas('cart_items', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);
});
