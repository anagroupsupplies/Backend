<?php

use App\Http\Middleware\AuthenticateFirebase;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\NewOrderForSellerNotification;
use App\Notifications\OrderPaymentConfirmedNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

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

test('checkout sends a mobile money push and stores the gps delivery location', function () {
    Notification::fake();
    config(['services.malipopay.api_token' => 'mp_sk_test', 'services.malipopay.webhook_secret' => 'shhh']);
    Http::fake(['*/api/v2/payment/collection' => Http::response(['success' => true, 'code' => 1109, 'data' => [
        'reference' => 'ML008985', 'status' => 'PROCESSING', 'id' => '6890f1c2a4b19e3f2c7d5a10',
        'customer' => ['mno' => 'Vodacom'], 'link' => 'https://app.malipopay.co.tz/ref?ML008985',
    ]])]);

    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey', 'price' => 50000, 'stock' => 4]);
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->actingAs($user);

    $response = $this->postJson('/api/v1/checkout', [
        'shippingDetails' => [
            'fullName' => 'Test Customer', 'email' => 'customer@example.com', 'phone' => '0712345678',
            'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
            'postalCode' => '11101', 'country' => 'Tanzania', 'deliveryNotes' => 'Blue gate',
        ],
        'paymentMethod' => 'mobile_money',
        'deliveryLocation' => ['latitude' => -6.7923542, 'longitude' => 39.2083284, 'accuracy' => 12.5],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.paymentMethod', 'mobile_money')
        ->assertJsonPath('data.paymentStatus', 'processing')
        ->assertJsonPath('data.paymentReference', 'ML008985')
        ->assertJsonPath('data.deliveryLocation.latitude', -6.7923542)
        ->assertJsonPath('data.paymentError', null);

    $order = Order::first();
    expect($order->shipping_details['deliveryNotes'])->toBe('Blue gate')
        ->and($order->payment_phone)->toBe('255712345678')
        ->and($order->payment_channel)->toBe('Vodacom');

    Http::assertSent(fn ($request) => $request['account'] === '255712345678'
        && $request['service'] === 'mobile'
        && $request['amount'] === 50000
        && $request['reference'] === $order->number
        && $request->hasHeader('apiToken', 'mp_sk_test'));

    Notification::assertSentTo($user, OrderPlacedNotification::class);
});

test('a failed mobile money push leaves the order unpaid instead of pretending it succeeded', function () {
    Notification::fake();
    config(['services.malipopay.api_token' => 'mp_sk_test']);
    Http::fake(['*/api/v2/payment/collection' => Http::response(['message' => 'Destination not whitelisted.'], 400)]);

    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey', 'price' => 50000, 'stock' => 4]);
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->actingAs($user);

    $this->postJson('/api/v1/checkout', [
        'shippingDetails' => [
            'fullName' => 'Test Customer', 'email' => 'customer@example.com', 'phone' => '0712345678',
            'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
            'postalCode' => '11101', 'country' => 'Tanzania',
        ],
        'paymentMethod' => 'mobile_money',
    ])->assertCreated()
        ->assertJsonPath('data.paymentStatus', 'failed')
        ->assertJsonPath('data.paymentError', 'Destination not whitelisted.');

    expect(Order::first()->isPaid())->toBeFalse();
});

test('checkout rejects a malformed mobile money number', function () {
    config(['services.malipopay.api_token' => 'mp_sk_test']);
    Http::fake();
    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey', 'price' => 50000, 'stock' => 4]);
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->actingAs($user);

    $this->postJson('/api/v1/checkout', [
        'shippingDetails' => [
            'fullName' => 'Test Customer', 'email' => 'customer@example.com', 'phone' => '12345',
            'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
            'postalCode' => '11101', 'country' => 'Tanzania',
        ],
        'paymentMethod' => 'mobile_money',
    ])->assertStatus(422);

    Http::assertNothingSent();
    expect(Order::count())->toBe(0);
});

test('checkout defaults to pay on delivery and rejects an unknown payment method', function () {
    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey', 'price' => 50000, 'stock' => 4]);
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->actingAs($user);

    $shipping = ['shippingDetails' => [
        'fullName' => 'Test Customer', 'email' => 'customer@example.com', 'phone' => '255700000000',
        'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
        'postalCode' => '11101', 'country' => 'Tanzania',
    ]];

    $this->postJson('/api/v1/checkout', [...$shipping, 'paymentMethod' => 'bitcoin'])
        ->assertStatus(422);

    $this->postJson('/api/v1/checkout', $shipping)
        ->assertCreated()
        ->assertJsonPath('data.paymentMethod', 'cash_on_delivery')
        ->assertJsonPath('data.deliveryLocation', null);
});

test('checkout rejects an out of range gps coordinate', function () {
    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey', 'price' => 50000, 'stock' => 4]);
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->actingAs($user);

    $this->postJson('/api/v1/checkout', [
        'shippingDetails' => [
            'fullName' => 'Test Customer', 'email' => 'customer@example.com', 'phone' => '255700000000',
            'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
            'postalCode' => '11101', 'country' => 'Tanzania',
        ],
        'deliveryLocation' => ['latitude' => 999, 'longitude' => 39.2083284],
    ])->assertStatus(422)->assertJsonValidationErrors('deliveryLocation.latitude');
});

/**
 * Build a signed MALIPOPAY webhook request as [raw body, server vars].
 *
 * `call()` reads headers from server variables rather than the withHeaders
 * bag, and the signature must cover the exact raw bytes we send.
 */
function malipoWebhook(array $payload, string $secret = 'shhh'): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return [$body, [
        'HTTP_X_MALIPOPAY_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ]];
}

function paidOrderFor(User $user): Order
{
    return Order::create(['number' => 'ANA-TEST-0001', 'user_id' => $user->id, 'subtotal' => 50000, 'total' => 50000, 'status' => 'pending', 'shipping_details' => ['fullName' => 'Test Customer', 'email' => 'customer@example.com'], 'payment_method' => 'mobile_money', 'payment_status' => 'processing', 'payment_reference' => 'ML008985']);
}

test('a signed malipopay webhook marks the order paid and emails the buyer', function () {
    Notification::fake();
    config(['services.malipopay.webhook_secret' => 'shhh']);
    $user = User::factory()->create();
    $order = paidOrderFor($user);

    [$body, $server] = malipoWebhook(['event' => 'payment.confirmed', 'status' => 'SUCCESSFUL', 'reference' => 'ML008985', 'customerReference' => $order->number, 'amount' => 50000, 'transactionId' => 'MP250001234567', 'type' => 'CHARGE', 'service' => 'Collection', 'customer' => ['mno' => 'Vodacom']]);

    $this->call('POST', '/api/v1/webhooks/malipopay', [], [], [], $server, $body)->assertOk();

    $order->refresh();
    expect($order->payment_status)->toBe('paid')
        ->and((float) $order->paid_amount)->toBe(50000.0)
        ->and($order->status)->toBe('confirmed')
        ->and($order->paid_at)->not->toBeNull();

    Notification::assertSentTo($user, OrderPaymentConfirmedNotification::class);
});

test('a malipopay webhook with a bad signature is rejected and changes nothing', function () {
    config(['services.malipopay.webhook_secret' => 'shhh']);
    $order = paidOrderFor(User::factory()->create());

    [$body, $server] = malipoWebhook(['event' => 'payment.confirmed', 'status' => 'SUCCESSFUL', 'customerReference' => $order->number, 'amount' => 50000], 'wrong-secret');

    $this->call('POST', '/api/v1/webhooks/malipopay', [], [], [], $server, $body)->assertStatus(401);

    expect($order->refresh()->payment_status)->toBe('processing');
});

test('a repeated malipopay webhook does not email the buyer twice', function () {
    Notification::fake();
    config(['services.malipopay.webhook_secret' => 'shhh']);
    $user = User::factory()->create();
    $order = paidOrderFor($user);

    [$body, $server] = malipoWebhook(['event' => 'payment.confirmed', 'status' => 'SUCCESSFUL', 'customerReference' => $order->number, 'amount' => 50000]);
    $this->call('POST', '/api/v1/webhooks/malipopay', [], [], [], $server, $body)->assertOk();
    $this->call('POST', '/api/v1/webhooks/malipopay', [], [], [], $server, $body)->assertOk();

    Notification::assertSentToTimes($user, OrderPaymentConfirmedNotification::class, 1);
});

test('a failed malipopay webhook records the reason without marking the order paid', function () {
    config(['services.malipopay.webhook_secret' => 'shhh']);
    $order = paidOrderFor(User::factory()->create());

    [$body, $server] = malipoWebhook(['event' => 'payment.failed', 'status' => 'REJECTED', 'customerReference' => $order->number, 'amount' => 0, 'failureReason' => 'Customer declined the prompt.']);

    $this->call('POST', '/api/v1/webhooks/malipopay', [], [], [], $server, $body)->assertOk();

    $order->refresh();
    expect($order->payment_status)->toBe('failed')
        ->and($order->payment_failure_reason)->toBe('Customer declined the prompt.')
        ->and($order->paid_at)->toBeNull();
});

test('unpaid orders are excluded from admin revenue', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $buyer = User::factory()->create();
    paidOrderFor($buyer); // processing, unpaid
    Order::create(['number' => 'ANA-TEST-0002', 'user_id' => $buyer->id, 'subtotal' => 30000, 'total' => 30000, 'status' => 'delivered', 'shipping_details' => [], 'payment_method' => 'cash_on_delivery', 'payment_status' => 'paid', 'paid_amount' => 30000, 'paid_at' => now()]);
    $this->actingAs($admin);

    $this->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('data.revenue', 30000)
        ->assertJsonPath('data.pendingRevenue', 50000);
});

test('delivering a cash order marks it paid so it counts as revenue', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $buyer = User::factory()->create();
    $order = Order::create(['number' => 'ANA-TEST-0003', 'user_id' => $buyer->id, 'subtotal' => 20000, 'total' => 20000, 'status' => 'pending', 'shipping_details' => [], 'payment_method' => 'cash_on_delivery', 'payment_status' => 'pending']);
    $this->actingAs($admin);

    $this->patchJson("/api/v1/admin/orders/{$order->id}", ['status' => 'delivered'])->assertOk();

    expect($order->refresh()->payment_status)->toBe('paid')
        ->and((float) $order->paid_amount)->toBe(20000.0);
});

test('a seller can only open an order that contains their own items', function () {
    $sellerA = User::factory()->create(['role' => 'seller']);
    $sellerB = User::factory()->create(['role' => 'seller']);
    $buyer = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey-a', 'price' => 50000, 'stock' => 4, 'seller_id' => $sellerA->id]);
    $order = Order::create(['number' => 'ANA-TEST-0004', 'user_id' => $buyer->id, 'subtotal' => 50000, 'total' => 50000, 'status' => 'pending', 'shipping_details' => [], 'delivery_latitude' => -6.79, 'delivery_longitude' => 39.20]);
    $order->items()->create(['product_id' => $product->id, 'seller_id' => $sellerA->id, 'name' => 'Jersey', 'unit_price' => 50000, 'quantity' => 1]);

    $this->actingAs($sellerA)->getJson("/api/v1/seller/orders/{$order->id}")->assertOk()->assertJsonPath('data.number', 'ANA-TEST-0004');
    $this->actingAs($sellerB)->getJson("/api/v1/seller/orders/{$order->id}")->assertNotFound();
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

test('the public shop page returns an active shop by slug with default branding', function () {
    $seller = User::factory()->create(['role' => 'seller']);
    Shop::create(['seller_id' => $seller->id, 'name' => 'Cool Store', 'slug' => 'cool-store']);

    $this->getJson('/api/v1/shops/cool-store')
        ->assertOk()
        ->assertJsonPath('data.name', 'Cool Store')
        ->assertJsonPath('data.slug', 'cool-store')
        ->assertJsonPath('data.primaryColor', '#3157d5');
});

test('an inactive or unknown shop slug returns 404', function () {
    $seller = User::factory()->create(['role' => 'seller']);
    Shop::create(['seller_id' => $seller->id, 'name' => 'Hidden Store', 'slug' => 'hidden-store', 'is_active' => false]);

    $this->getJson('/api/v1/shops/hidden-store')->assertNotFound();
    $this->getJson('/api/v1/shops/does-not-exist')->assertNotFound();
});

test('a seller can view and update their own shop branding', function () {
    $seller = User::factory()->create(['role' => 'seller']);
    $this->actingAs($seller);

    $this->getJson('/api/v1/seller/shop')->assertOk()->assertJsonPath('data.primaryColor', '#3157d5');

    $this->patchJson('/api/v1/seller/shop', [
        'name' => 'My Rebranded Shop',
        'logo' => 'https://example.test/logo.png',
        'primaryColor' => '#ff0000',
        'accentColor' => '#00ff00',
    ])->assertOk()
        ->assertJsonPath('data.name', 'My Rebranded Shop')
        ->assertJsonPath('data.logo', 'https://example.test/logo.png')
        ->assertJsonPath('data.primaryColor', '#ff0000')
        ->assertJsonPath('data.accentColor', '#00ff00');

    expect(Shop::where('seller_id', $seller->id)->first()->settings)
        ->toMatchArray(['primaryColor' => '#ff0000', 'accentColor' => '#00ff00']);
});

test('a seller cannot affect another sellers shop through the shop endpoints', function () {
    $sellerA = User::factory()->create(['role' => 'seller']);
    $sellerB = User::factory()->create(['role' => 'seller']);
    $shopB = Shop::create(['seller_id' => $sellerB->id, 'name' => "B's Shop", 'slug' => 'bs-shop']);

    $this->actingAs($sellerA);
    $this->patchJson('/api/v1/seller/shop', ['name' => 'Hijacked'])->assertOk();

    expect($shopB->fresh()->name)->toBe("B's Shop");
});

/**
 * Render BOTH halves of a mail message. MailMessage::render() only compiles the
 * HTML view, so the plain-text Blade view needs rendering explicitly or a syntax
 * error in it reaches production unnoticed.
 */
function renderMail(MailMessage $mail): string
{
    $textView = is_array($mail->view) ? ($mail->view['text'] ?? null) : null;

    return $mail->render()."\n".($textView ? view($textView, $mail->viewData)->render() : '');
}

test('the order emails render without blade errors', function () {
    $buyer = User::factory()->create(['name' => 'Buyer One']);
    $seller = User::factory()->create(['role' => 'seller', 'name' => 'Seller One']);
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey-mail', 'price' => 50000, 'stock' => 4, 'seller_id' => $seller->id]);
    $order = Order::create(['number' => 'ANA-TEST-MAIL', 'user_id' => $buyer->id, 'subtotal' => 50000, 'total' => 50000, 'status' => 'pending', 'shipping_details' => ['fullName' => 'Buyer One', 'email' => 'buyer@example.com', 'phone' => '255712345678', 'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam', 'postalCode' => '11101', 'country' => 'Tanzania', 'deliveryNotes' => 'Blue gate'], 'payment_method' => 'mobile_money', 'payment_status' => 'paid', 'paid_amount' => 50000, 'paid_at' => now(), 'payment_reference' => 'ML008985', 'payment_channel' => 'Vodacom', 'delivery_latitude' => -6.79, 'delivery_longitude' => 39.20]);
    $item = $order->items()->create(['product_id' => $product->id, 'seller_id' => $seller->id, 'name' => 'Jersey', 'unit_price' => 50000, 'quantity' => 1, 'selected_size' => 'L']);
    $order->load('items');

    $placed = renderMail((new OrderPlacedNotification($order))->toMail($buyer));
    $confirmed = renderMail((new OrderPaymentConfirmedNotification($order))->toMail($buyer));
    $sellerMail = renderMail((new NewOrderForSellerNotification($order, $order->items))->toMail($seller));

    expect($placed)->toContain('ANA-TEST-MAIL')->toContain('Buyer One')
        ->and($confirmed)->toContain('ML008985')
        ->and($sellerMail)->toContain('Seller One')->toContain('maps.google.com')->toContain($item->name);
});

/** An order containing one line from each of two sellers. */
function multiSellerOrder(User $sellerA, User $sellerB, User $buyer, string $number = 'ANA-MULTI-1'): Order
{
    $productA = Product::create(['name' => 'Jersey A', 'slug' => 'jersey-a-'.$number, 'price' => 30000, 'stock' => 5, 'seller_id' => $sellerA->id]);
    $productB = Product::create(['name' => 'Jersey B', 'slug' => 'jersey-b-'.$number, 'price' => 20000, 'stock' => 5, 'seller_id' => $sellerB->id]);
    $order = Order::create(['number' => $number, 'user_id' => $buyer->id, 'subtotal' => 50000, 'total' => 50000, 'status' => 'pending', 'shipping_details' => ['fullName' => 'Buyer One', 'phone' => '255712345678']]);
    $order->items()->create(['product_id' => $productA->id, 'seller_id' => $sellerA->id, 'name' => 'Jersey A', 'unit_price' => 30000, 'quantity' => 1]);
    $order->items()->create(['product_id' => $productB->id, 'seller_id' => $sellerB->id, 'name' => 'Jersey B', 'unit_price' => 20000, 'quantity' => 1]);

    return $order;
}

test('a seller advancing their own lines does not move another seller lines', function () {
    Notification::fake();
    $sellerA = User::factory()->create(['role' => 'seller']);
    $sellerB = User::factory()->create(['role' => 'seller']);
    $buyer = User::factory()->create();
    $order = multiSellerOrder($sellerA, $sellerB, $buyer);

    $this->actingAs($sellerA)->patchJson("/api/v1/seller/orders/{$order->id}/status", ['status' => 'shipped'])->assertOk();

    expect($order->items()->where('seller_id', $sellerA->id)->value('fulfillment_status'))->toBe('shipped')
        ->and($order->items()->where('seller_id', $sellerB->id)->value('fulfillment_status'))->toBe('pending')
        ->and($order->refresh()->status)->toBe('pending');

    Notification::assertSentTo($buyer, OrderStatusUpdatedNotification::class);
});

test('the order status advances once every seller has shipped', function () {
    Notification::fake();
    $sellerA = User::factory()->create(['role' => 'seller']);
    $sellerB = User::factory()->create(['role' => 'seller']);
    $buyer = User::factory()->create();
    $order = multiSellerOrder($sellerA, $sellerB, $buyer);

    $this->actingAs($sellerA)->patchJson("/api/v1/seller/orders/{$order->id}/status", ['status' => 'shipped'])->assertOk();
    $this->actingAs($sellerB)->patchJson("/api/v1/seller/orders/{$order->id}/status", ['status' => 'shipped'])->assertOk();

    expect($order->refresh()->status)->toBe('shipped');
});

test('a seller cannot change the status of an order that has none of their items', function () {
    $sellerA = User::factory()->create(['role' => 'seller']);
    $outsider = User::factory()->create(['role' => 'seller']);
    $buyer = User::factory()->create();
    $order = multiSellerOrder($sellerA, User::factory()->create(['role' => 'seller']), $buyer);

    $this->actingAs($outsider)->patchJson("/api/v1/seller/orders/{$order->id}/status", ['status' => 'shipped'])->assertNotFound();

    expect($order->refresh()->status)->toBe('pending');
});

test('a seller cannot set a status outside the allowed vocabulary', function () {
    $sellerA = User::factory()->create(['role' => 'seller']);
    $buyer = User::factory()->create();
    $order = multiSellerOrder($sellerA, User::factory()->create(['role' => 'seller']), $buyer);

    $this->actingAs($sellerA)->patchJson("/api/v1/seller/orders/{$order->id}/status", ['status' => 'refunded'])
        ->assertStatus(422)->assertJsonValidationErrors('status');
});

test('an unchanged status does not spam the buyer with another email', function () {
    Notification::fake();
    $sellerA = User::factory()->create(['role' => 'seller']);
    $buyer = User::factory()->create();
    $order = multiSellerOrder($sellerA, User::factory()->create(['role' => 'seller']), $buyer);

    $this->actingAs($sellerA)->patchJson("/api/v1/seller/orders/{$order->id}/status", ['status' => 'shipped'])->assertOk();
    $this->actingAs($sellerA)->patchJson("/api/v1/seller/orders/{$order->id}/status", ['status' => 'shipped'])->assertOk();

    Notification::assertSentToTimes($buyer, OrderStatusUpdatedNotification::class, 1);
});

test('an admin status change emails the buyer', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $buyer = User::factory()->create();
    $order = Order::create(['number' => 'ANA-ADMIN-1', 'user_id' => $buyer->id, 'subtotal' => 10000, 'total' => 10000, 'status' => 'pending', 'shipping_details' => []]);

    $this->actingAs($admin)->patchJson("/api/v1/admin/orders/{$order->id}", ['status' => 'processing'])->assertOk();

    Notification::assertSentTo($buyer, OrderStatusUpdatedNotification::class);
});

test('seller orders can be searched, filtered, and paginated', function () {
    $seller = User::factory()->create(['role' => 'seller']);
    $other = User::factory()->create(['role' => 'seller']);
    $buyer = User::factory()->create();
    $first = multiSellerOrder($seller, $other, $buyer, 'ANA-FIND-ME');
    multiSellerOrder($seller, $other, $buyer, 'ANA-OTHER-1');
    $this->actingAs($seller);

    $this->getJson('/api/v1/seller/orders?search=FIND-ME')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.orders.0.number', 'ANA-FIND-ME');

    $this->getJson('/api/v1/seller/orders?perPage=5&sort=oldest')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 2)
        ->assertJsonCount(2, 'data.orders');

    $this->patchJson("/api/v1/seller/orders/{$first->id}/status", ['status' => 'shipped'])->assertOk();

    $this->getJson('/api/v1/seller/orders?status=shipped')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.orders.0.number', 'ANA-FIND-ME');

    $this->getJson('/api/v1/seller/orders')->assertOk()->assertJsonPath('data.summary.shipped', 1);
});

test('seller order search only ever returns orders containing their own items', function () {
    $seller = User::factory()->create(['role' => 'seller']);
    $stranger = User::factory()->create(['role' => 'seller']);
    $buyer = User::factory()->create();
    $strangerProduct = Product::create(['name' => 'Not mine', 'slug' => 'not-mine', 'price' => 9000, 'stock' => 3, 'seller_id' => $stranger->id]);
    $order = Order::create(['number' => 'ANA-STRANGER', 'user_id' => $buyer->id, 'subtotal' => 9000, 'total' => 9000, 'status' => 'pending', 'shipping_details' => []]);
    $order->items()->create(['product_id' => $strangerProduct->id, 'seller_id' => $stranger->id, 'name' => 'Not mine', 'unit_price' => 9000, 'quantity' => 1]);

    $this->actingAs($seller)->getJson('/api/v1/seller/orders?search=STRANGER')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 0);
});

test('the status update email renders for every status', function () {
    $buyer = User::factory()->create(['name' => 'Buyer One']);
    $order = Order::create(['number' => 'ANA-STATUS-MAIL', 'user_id' => $buyer->id, 'subtotal' => 10000, 'total' => 10000, 'status' => 'shipped', 'shipping_details' => ['fullName' => 'Buyer One', 'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam']]);

    foreach (['confirmed', 'processing', 'shipped', 'delivered', 'cancelled'] as $status) {
        $rendered = renderMail((new OrderStatusUpdatedNotification($order, $status, 'Shop One'))->toMail($buyer));
        expect($rendered)->toContain('ANA-STATUS-MAIL')->toContain('Shop One');
    }
});

test('the master admin can switch mobile money off and on', function () {
    config(['services.malipopay.api_token' => 'mp_sk_test']);
    $master = User::factory()->create(['role' => 'master']);
    $this->actingAs($master);

    // On by default once credentials exist.
    $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.mobileMoneyAvailable', true);

    $this->patchJson('/api/v1/admin/settings/mobile-money', ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.mobileMoneyEnabled', false)
        ->assertJsonPath('data.mobileMoneyAvailable', false);

    $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.mobileMoneyEnabled', false);

    $this->patchJson('/api/v1/admin/settings/mobile-money', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.mobileMoneyAvailable', true);
});

test('switching mobile money off blocks checkout server side even if the client still asks for it', function () {
    Notification::fake();
    config(['services.malipopay.api_token' => 'mp_sk_test']);
    Http::fake();
    Setting::putGeneral(['mobileMoneyEnabled' => false]);

    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey-off', 'price' => 50000, 'stock' => 4]);
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->actingAs($user);

    $this->postJson('/api/v1/checkout', [
        'shippingDetails' => [
            'fullName' => 'Test Customer', 'email' => 'customer@example.com', 'phone' => '0712345678',
            'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
            'postalCode' => '11101', 'country' => 'Tanzania',
        ],
        'paymentMethod' => 'mobile_money',
    ])->assertStatus(503);

    Http::assertNothingSent();
    expect(Order::count())->toBe(0);
});

test('pay on delivery still works while mobile money is switched off', function () {
    Notification::fake();
    Setting::putGeneral(['mobileMoneyEnabled' => false]);

    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey-cod', 'price' => 50000, 'stock' => 4]);
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->actingAs($user);

    $this->postJson('/api/v1/checkout', ['shippingDetails' => [
        'fullName' => 'Test Customer', 'email' => 'customer@example.com', 'phone' => '0712345678',
        'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
        'postalCode' => '11101', 'country' => 'Tanzania',
    ]])->assertCreated()->assertJsonPath('data.paymentMethod', 'cash_on_delivery');
});

test('mobile money reports unavailable when switched on but credentials are missing', function () {
    config(['services.malipopay.api_token' => '']);
    Setting::putGeneral(['mobileMoneyEnabled' => true]);

    $this->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('data.mobileMoneyEnabled', true)
        ->assertJsonPath('data.mobileMoneyConfigured', false)
        ->assertJsonPath('data.mobileMoneyAvailable', false);
});

test('a non master administrator cannot switch mobile money', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->patchJson('/api/v1/admin/settings/mobile-money', ['enabled' => false])->assertStatus(403);

    expect(Setting::general()['mobileMoneyEnabled'])->toBeTrue();
});

test('saving general settings does not wipe the mobile money switch', function () {
    $master = User::factory()->create(['role' => 'master']);
    Setting::putGeneral(['mobileMoneyEnabled' => false]);
    $this->actingAs($master);

    $this->putJson('/api/v1/admin/settings', ['whatsappNumber' => '255700000001'])->assertOk();

    expect(Setting::general()['mobileMoneyEnabled'])->toBeFalse()
        ->and(Setting::general()['whatsappNumber'])->toBe('255700000001');
});

/** @return array<string, mixed> */
function addressPayload(array $overrides = []): array
{
    return [...[
        'label' => 'Home',
        'fullName' => 'Buyer One',
        'email' => 'buyer@example.com',
        'phone' => '0712345678',
        'streetAddress' => '1 Test Street',
        'city' => 'Dar es Salaam',
        'state' => 'Dar es Salaam',
        'postalCode' => '11101',
        'country' => 'Tanzania',
    ], ...$overrides];
}

test('a customer can save, list, update and delete their delivery addresses', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // The first saved address becomes the default automatically.
    $created = $this->postJson('/api/v1/addresses', addressPayload())
        ->assertCreated()
        ->assertJsonPath('data.label', 'Home')
        ->assertJsonPath('data.isDefault', true)
        ->json('data');

    $this->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(1, 'data');

    $this->patchJson("/api/v1/addresses/{$created['id']}", ['label' => 'Office', 'city' => 'Arusha'])
        ->assertOk()
        ->assertJsonPath('data.label', 'Office')
        ->assertJsonPath('data.city', 'Arusha');

    $this->deleteJson("/api/v1/addresses/{$created['id']}")->assertNoContent();
    expect(Address::count())->toBe(0);
});

test('saving a second address as default demotes the first', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $first = $this->postJson('/api/v1/addresses', addressPayload())->assertCreated()->json('data');
    $second = $this->postJson('/api/v1/addresses', addressPayload(['label' => 'Office', 'isDefault' => true]))->assertCreated()->json('data');

    expect(Address::find($first['id'])->is_default)->toBeFalse()
        ->and(Address::find($second['id'])->is_default)->toBeTrue();
});

test('deleting the default address promotes another one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $first = $this->postJson('/api/v1/addresses', addressPayload())->assertCreated()->json('data');
    $this->postJson('/api/v1/addresses', addressPayload(['label' => 'Office']))->assertCreated();

    $this->deleteJson("/api/v1/addresses/{$first['id']}")->assertNoContent();

    expect(Address::where('user_id', $user->id)->where('is_default', true)->count())->toBe(1);
});

test('a customer cannot read or change another customer saved address', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $this->actingAs($owner);
    $address = $this->postJson('/api/v1/addresses', addressPayload())->assertCreated()->json('data');

    $this->actingAs($stranger);
    $this->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(0, 'data');
    $this->patchJson("/api/v1/addresses/{$address['id']}", ['city' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/v1/addresses/{$address['id']}")->assertNotFound();

    expect(Address::find($address['id'])->city)->toBe('Dar es Salaam');
});

test('checkout can save the delivery address for reuse without duplicating it', function () {
    Notification::fake();
    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey-addr', 'price' => 50000, 'stock' => 9]);
    $this->actingAs($user);

    $body = [
        'shippingDetails' => [
            'fullName' => 'Buyer One', 'email' => 'buyer@example.com', 'phone' => '0712345678',
            'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
            'postalCode' => '11101', 'country' => 'Tanzania', 'deliveryNotes' => 'Blue gate',
        ],
        'saveAddress' => true,
        'addressLabel' => 'Home',
        'deliveryLocation' => ['latitude' => -6.79, 'longitude' => 39.20],
    ];

    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->postJson('/api/v1/checkout', $body)->assertCreated();

    // Ordering again with the same address must not create a second copy.
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->postJson('/api/v1/checkout', $body)->assertCreated();

    $saved = Address::where('user_id', $user->id)->get();
    expect($saved)->toHaveCount(1)
        ->and($saved->first()->label)->toBe('Home')
        ->and($saved->first()->is_default)->toBeTrue()
        ->and((float) $saved->first()->latitude)->toBe(-6.79);
});

test('checkout does not save the address unless asked', function () {
    Notification::fake();
    $user = User::factory()->create();
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey-nosave', 'price' => 50000, 'stock' => 4]);
    CartItem::create(['user_id' => $user->id, 'product_id' => $product->id, 'selected_size' => 'none', 'quantity' => 1]);
    $this->actingAs($user);

    $this->postJson('/api/v1/checkout', ['shippingDetails' => [
        'fullName' => 'Buyer One', 'email' => 'buyer@example.com', 'phone' => '0712345678',
        'streetAddress' => '1 Test Street', 'city' => 'Dar es Salaam', 'state' => 'Dar es Salaam',
        'postalCode' => '11101', 'country' => 'Tanzania',
    ]])->assertCreated();

    expect(Address::count())->toBe(0);
});

test('a customer can update their own profile including the address field', function () {
    $user = User::factory()->create(['name' => 'Old Name']);
    $this->actingAs($user);

    $this->patchJson('/api/v1/me', ['name' => 'New Name', 'phone' => '0712345678', 'address' => 'Mikocheni B, Dar es Salaam'])
        ->assertOk()
        ->assertJsonPath('data.displayName', 'New Name')
        ->assertJsonPath('data.address', 'Mikocheni B, Dar es Salaam');

    expect($user->refresh()->name)->toBe('New Name')
        ->and($user->address)->toBe('Mikocheni B, Dar es Salaam');
});
