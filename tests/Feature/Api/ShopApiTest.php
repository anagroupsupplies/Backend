<?php

use App\Http\Middleware\AuthenticateFirebase;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\EscrowHolding;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\NewOrderForSellerNotification;
use App\Notifications\OrderPaymentConfirmedNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderStatusUpdatedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SellerApplicationStatusNotification;
use App\Notifications\TicketOpenedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Services\EscrowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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

test('only the master admin can read the audit trail', function () {
    $master = User::factory()->create(['role' => 'master']);
    $admin = User::factory()->create(['role' => 'admin']);
    $seller = User::factory()->create(['role' => 'seller']);
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
    $this->actingAs($seller)->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
    $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
    $this->actingAs($master)->getJson('/api/v1/admin/audit-logs')->assertOk();
});

test('a role change is recorded with who did it and what changed', function () {
    $master = User::factory()->create(['role' => 'master', 'name' => 'Master One', 'email' => 'master@example.com']);
    $target = User::factory()->create(['role' => 'user', 'email' => 'target@example.com']);

    $this->actingAs($master)->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'seller'])->assertOk();

    $log = AuditLog::where('action', 'user.role_changed')->firstOrFail();
    expect($log->actor_email)->toBe('master@example.com')
        ->and($log->actor_role)->toBe('master')
        ->and($log->user_id)->toBe($master->id)
        ->and($log->auditable_id)->toBe($target->id)
        ->and($log->changes['role']['from'])->toBe('user')
        ->and($log->changes['role']['to'])->toBe('seller')
        ->and($log->ip_address)->not->toBeNull();
});

test('suspending an account and deleting one are both recorded', function () {
    $master = User::factory()->create(['role' => 'master']);
    $target = User::factory()->create(['email' => 'victim@example.com']);
    $this->actingAs($master);

    $this->patchJson("/api/v1/admin/users/{$target->id}", ['isActive' => false])->assertOk();
    $this->deleteJson("/api/v1/admin/users/{$target->id}")->assertNoContent();

    expect(AuditLog::where('action', 'user.status_changed')->count())->toBe(1)
        // The deleted account's details survive in the trail.
        ->and(AuditLog::where('action', 'user.deleted')->first()->changes['email'])->toBe('victim@example.com');
});

test('toggling mobile money is recorded for oversight', function () {
    $master = User::factory()->create(['role' => 'master']);

    $this->actingAs($master)->patchJson('/api/v1/admin/settings/mobile-money', ['enabled' => false])->assertOk();

    $log = AuditLog::where('action', 'settings.mobile_money_toggled')->firstOrFail();
    expect($log->changes['mobileMoneyEnabled']['to'])->toBeFalse()
        ->and($log->description)->toContain('OFF');
});

test('a product price change is recorded', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey-audit', 'price' => 50000, 'stock' => 4]);

    $this->actingAs($admin)->patchJson("/api/v1/admin/products/{$product->id}", ['price' => 75000])->assertOk();

    $log = AuditLog::where('action', 'product.updated')->firstOrFail();
    expect((float) $log->changes['price']['from'])->toBe(50000.0)
        ->and((float) $log->changes['price']['to'])->toBe(75000.0);
});

test('an order status change is recorded against the order', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $buyer = User::factory()->create();
    $order = Order::create(['number' => 'ANA-AUDIT-1', 'user_id' => $buyer->id, 'subtotal' => 1000, 'total' => 1000, 'status' => 'pending', 'shipping_details' => []]);
    Notification::fake();

    $this->actingAs($admin)->patchJson("/api/v1/admin/orders/{$order->id}", ['status' => 'shipped'])->assertOk();

    $log = AuditLog::where('action', 'order.status_changed')->firstOrFail();
    expect($log->changes['status']['from'])->toBe('pending')
        ->and($log->changes['status']['to'])->toBe('shipped')
        ->and($log->auditable_id)->toBe($order->id);
});

test('a confirmed payment is recorded against the system actor', function () {
    Notification::fake();
    config(['services.malipopay.webhook_secret' => 'shhh']);
    $order = paidOrderFor(User::factory()->create());

    [$body, $server] = malipoWebhook(['event' => 'payment.confirmed', 'status' => 'SUCCESSFUL', 'customerReference' => $order->number, 'amount' => 50000, 'transactionId' => 'MP250001234567']);
    $this->call('POST', '/api/v1/webhooks/malipopay', [], [], [], $server, $body)->assertOk();

    $log = AuditLog::where('action', 'order.payment_confirmed')->firstOrFail();
    expect($log->user_id)->toBeNull()
        ->and($log->actor_name)->toBe('System')
        ->and($log->actor_role)->toBe('system')
        ->and((float) $log->changes['amount'])->toBe(50000.0);
});

test('an administrator sign in is recorded but a customer sign in is not', function () {
    User::factory()->create(['email' => 'boss@example.com', 'password' => 'password123', 'role' => 'master']);
    User::factory()->create(['email' => 'shopper@example.com', 'password' => 'password123', 'role' => 'user']);

    $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.com', 'password' => 'password123'])->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => 'shopper@example.com', 'password' => 'password123'])->assertOk();

    expect(AuditLog::where('action', 'auth.admin_signed_in')->count())->toBe(1)
        ->and(AuditLog::where('action', 'auth.admin_signed_in')->first()->actor_email)->toBe('boss@example.com');
});

test('audit entries cannot be edited or deleted, even in code', function () {
    $log = AuditLog::create(['action' => 'settings.updated', 'actor_name' => 'Master One', 'created_at' => now()]);

    expect(fn () => $log->update(['action' => 'product.created']))->toThrow(RuntimeException::class)
        ->and(fn () => $log->delete())->toThrow(RuntimeException::class)
        ->and(AuditLog::find($log->id)->action)->toBe('settings.updated');
});

test('the audit trail can be searched and filtered by action', function () {
    $master = User::factory()->create(['role' => 'master', 'name' => 'Master One']);
    $target = User::factory()->create(['email' => 'needle@example.com']);
    $this->actingAs($master);

    $this->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'seller'])->assertOk();
    $this->patchJson('/api/v1/admin/settings/mobile-money', ['enabled' => false])->assertOk();

    $this->getJson('/api/v1/admin/audit-logs?action=user.role_changed')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.logs.0.action', 'user.role_changed');

    $this->getJson('/api/v1/admin/audit-logs?search=needle@example.com')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1);

    $all = $this->getJson('/api/v1/admin/audit-logs')->assertOk()->assertJsonPath('data.meta.total', 2)->json('data');
    // Action keys contain dots, so read the counts rather than using a JSON path.
    expect($all['actions']['settings.mobile_money_toggled'])->toBe(1)
        ->and($all['actions']['user.role_changed'])->toBe(1);
});

test('a failure to write the audit trail never breaks the action itself', function () {
    $master = User::factory()->create(['role' => 'master']);
    $target = User::factory()->create();
    // Simulate the audit table being unavailable.
    Schema::drop('audit_logs');

    $this->actingAs($master)->patchJson("/api/v1/admin/users/{$target->id}", ['role' => 'seller'])->assertOk();

    expect($target->refresh()->role)->toBe('seller');
});

/** A seller with a shop and one product. */
function sellerWithProduct(string $slug = 'ticket-jersey'): array
{
    $seller = User::factory()->create(['role' => 'seller', 'name' => 'Seller One']);
    $shop = Shop::create(['seller_id' => $seller->id, 'name' => 'Seller One Shop', 'slug' => 'seller-one-shop-'.$slug]);
    $product = Product::create(['name' => 'Jersey', 'slug' => $slug, 'price' => 50000, 'stock' => 5, 'seller_id' => $seller->id, 'shop_id' => $shop->id]);

    return [$seller, $shop, $product];
}

test('a customer opens a ticket about a product and it reaches that seller', function () {
    Notification::fake();
    [$seller, $shop, $product] = sellerWithProduct();
    $buyer = User::factory()->create(['name' => 'Buyer One']);

    $response = $this->actingAs($buyer)->postJson('/api/v1/tickets', [
        'subject' => 'Is this available in blue?',
        'message' => 'Hello, I would like to know if this comes in blue.',
        'category' => 'product',
        'productId' => $product->id,
    ])->assertCreated();

    $ticket = Ticket::firstOrFail();
    expect($ticket->seller_id)->toBe($seller->id)
        ->and($ticket->shop_id)->toBe($shop->id)
        ->and($ticket->status)->toBe('open')
        ->and($ticket->reference)->toStartWith('TKT-')
        ->and($ticket->messages()->count())->toBe(1);

    $response->assertJsonPath('data.shopName', 'Seller One Shop')
        ->assertJsonPath('data.messages.0.body', 'Hello, I would like to know if this comes in blue.');

    // Both sides are told.
    Notification::assertSentTo($seller, TicketOpenedNotification::class);
    Notification::assertSentTo($buyer, TicketOpenedNotification::class);
});

test('a ticket with no shop attached goes to the administrators', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'master']);
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->postJson('/api/v1/tickets', [
        'subject' => 'I cannot update my account',
        'message' => 'My profile will not save.',
        'category' => 'account',
    ])->assertCreated();

    expect(Ticket::first()->seller_id)->toBeNull();
    Notification::assertSentTo($admin, TicketOpenedNotification::class);
});

test('a seller only sees tickets addressed to their own shop', function () {
    Notification::fake();
    [$sellerA, , $productA] = sellerWithProduct('jersey-a');
    [$sellerB, , $productB] = sellerWithProduct('jersey-b');
    $buyer = User::factory()->create();

    $this->actingAs($buyer);
    $this->postJson('/api/v1/tickets', ['subject' => 'For A', 'message' => 'hi A', 'productId' => $productA->id])->assertCreated();
    $this->postJson('/api/v1/tickets', ['subject' => 'For B', 'message' => 'hi B', 'productId' => $productB->id])->assertCreated();

    $forA = Ticket::where('subject', 'For A')->firstOrFail();
    $forB = Ticket::where('subject', 'For B')->firstOrFail();

    $this->actingAs($sellerA)->getJson('/api/v1/tickets')
        ->assertOk()
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.tickets.0.subject', 'For A');

    $this->actingAs($sellerA)->getJson("/api/v1/tickets/{$forB->id}")->assertNotFound();
    $this->actingAs($sellerA)->postJson("/api/v1/tickets/{$forB->id}/messages", ['message' => 'sneaking in'])->assertNotFound();
    $this->actingAs($sellerA)->getJson("/api/v1/tickets/{$forA->id}")->assertOk();

    expect($forB->messages()->count())->toBe(1);
});

test('a customer cannot read another customer ticket', function () {
    Notification::fake();
    [, , $product] = sellerWithProduct();
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($buyer)->postJson('/api/v1/tickets', ['subject' => 'Mine', 'message' => 'private', 'productId' => $product->id])->assertCreated();
    $ticket = Ticket::firstOrFail();

    $this->actingAs($stranger)->getJson('/api/v1/tickets')->assertOk()->assertJsonPath('data.meta.total', 0);
    $this->actingAs($stranger)->getJson("/api/v1/tickets/{$ticket->id}")->assertNotFound();
});

test('replies move the ticket between the customer and the shop', function () {
    Notification::fake();
    [$seller, , $product] = sellerWithProduct();
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->postJson('/api/v1/tickets', ['subject' => 'Help', 'message' => 'first', 'productId' => $product->id])->assertCreated();
    $ticket = Ticket::firstOrFail();

    // Shop answers -> waiting on the customer.
    $this->actingAs($seller)->postJson("/api/v1/tickets/{$ticket->id}/messages", ['message' => 'Yes we have it'])->assertCreated();
    expect($ticket->refresh()->status)->toBe('pending');
    Notification::assertSentTo($buyer, TicketRepliedNotification::class);

    // Customer answers -> back to the shop.
    $this->actingAs($buyer)->postJson("/api/v1/tickets/{$ticket->id}/messages", ['message' => 'Great, thanks'])->assertCreated();
    expect($ticket->refresh()->status)->toBe('open')
        ->and($ticket->messages()->count())->toBe(3);
    Notification::assertSentTo($seller, TicketRepliedNotification::class);
});

test('internal notes are visible to the shop but never to the customer', function () {
    Notification::fake();
    [$seller, , $product] = sellerWithProduct();
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->postJson('/api/v1/tickets', ['subject' => 'Help', 'message' => 'first', 'productId' => $product->id])->assertCreated();
    $ticket = Ticket::firstOrFail();

    $this->actingAs($seller)->postJson("/api/v1/tickets/{$ticket->id}/messages", ['message' => 'Check stock before replying', 'isInternal' => true])->assertCreated();

    $this->actingAs($seller)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk()->assertJsonCount(2, 'data.messages');
    $customerView = $this->actingAs($buyer)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
    $customerView->assertJsonCount(1, 'data.messages');
    expect(collect($customerView->json('data.messages'))->pluck('body'))->not->toContain('Check stock before replying');

    // An internal note is not an answer, so the customer is not emailed.
    Notification::assertNotSentTo($buyer, TicketRepliedNotification::class);
});

test('a customer cannot disguise a reply as an internal note', function () {
    Notification::fake();
    [, , $product] = sellerWithProduct();
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->postJson('/api/v1/tickets', ['subject' => 'Help', 'message' => 'first', 'productId' => $product->id])->assertCreated();
    $ticket = Ticket::firstOrFail();

    $this->actingAs($buyer)->postJson("/api/v1/tickets/{$ticket->id}/messages", ['message' => 'not really internal', 'isInternal' => true])->assertCreated();

    expect($ticket->messages()->latest('id')->first()->is_internal)->toBeFalse();
});

test('a customer may resolve or reopen their ticket but not set priority', function () {
    Notification::fake();
    [$seller, , $product] = sellerWithProduct();
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->postJson('/api/v1/tickets', ['subject' => 'Help', 'message' => 'first', 'productId' => $product->id])->assertCreated();
    $ticket = Ticket::firstOrFail();

    $this->actingAs($buyer)->patchJson("/api/v1/tickets/{$ticket->id}", ['status' => 'resolved'])->assertOk();
    expect($ticket->refresh()->status)->toBe('resolved')->and($ticket->closed_at)->not->toBeNull();

    $this->actingAs($buyer)->patchJson("/api/v1/tickets/{$ticket->id}", ['priority' => 'high'])->assertStatus(403);
    $this->actingAs($buyer)->patchJson("/api/v1/tickets/{$ticket->id}", ['status' => 'closed'])->assertStatus(403);

    // The shop has the full set.
    $this->actingAs($seller)->patchJson("/api/v1/tickets/{$ticket->id}", ['status' => 'closed', 'priority' => 'high'])->assertOk();
    expect($ticket->refresh()->status)->toBe('closed');
});

test('a closed ticket cannot receive new replies', function () {
    Notification::fake();
    [$seller, , $product] = sellerWithProduct();
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->postJson('/api/v1/tickets', ['subject' => 'Help', 'message' => 'first', 'productId' => $product->id])->assertCreated();
    $ticket = Ticket::firstOrFail();
    $this->actingAs($seller)->patchJson("/api/v1/tickets/{$ticket->id}", ['status' => 'closed'])->assertOk();

    $this->actingAs($buyer)->postJson("/api/v1/tickets/{$ticket->id}/messages", ['message' => 'one more thing'])->assertStatus(422);
});

test('an administrator can see and answer any ticket', function () {
    Notification::fake();
    [, , $product] = sellerWithProduct();
    $admin = User::factory()->create(['role' => 'admin']);
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->postJson('/api/v1/tickets', ['subject' => 'Help', 'message' => 'first', 'productId' => $product->id])->assertCreated();
    $ticket = Ticket::firstOrFail();

    $this->actingAs($admin)->getJson('/api/v1/tickets')->assertOk()->assertJsonPath('data.meta.total', 1);
    $this->actingAs($admin)->postJson("/api/v1/tickets/{$ticket->id}/messages", ['message' => 'Support here, looking into it'])->assertCreated();

    expect($ticket->refresh()->status)->toBe('pending');
    Notification::assertSentTo($buyer, TicketRepliedNotification::class);
});

test('a customer cannot attach someone else order to a ticket', function () {
    Notification::fake();
    $buyer = User::factory()->create();
    $otherBuyer = User::factory()->create();
    $order = Order::create(['number' => 'ANA-TICKET-1', 'user_id' => $otherBuyer->id, 'subtotal' => 1000, 'total' => 1000, 'status' => 'pending', 'shipping_details' => []]);

    $this->actingAs($buyer)->postJson('/api/v1/tickets', ['subject' => 'About an order', 'message' => 'hmm', 'orderId' => $order->id])->assertCreated();

    expect(Ticket::first()->order_id)->toBeNull();
});

test('tickets can be searched and filtered by status', function () {
    Notification::fake();
    [$seller, , $product] = sellerWithProduct();
    $buyer = User::factory()->create();
    $this->actingAs($buyer);
    $this->postJson('/api/v1/tickets', ['subject' => 'Broken zip', 'message' => 'a', 'productId' => $product->id])->assertCreated();
    $this->postJson('/api/v1/tickets', ['subject' => 'Late delivery', 'message' => 'b', 'productId' => $product->id])->assertCreated();
    $first = Ticket::where('subject', 'Broken zip')->firstOrFail();
    $this->actingAs($seller)->patchJson("/api/v1/tickets/{$first->id}", ['status' => 'resolved'])->assertOk();

    $this->actingAs($buyer)->getJson('/api/v1/tickets?search=zip')->assertOk()->assertJsonPath('data.meta.total', 1);
    $this->actingAs($buyer)->getJson('/api/v1/tickets?status=active')->assertOk()->assertJsonPath('data.meta.total', 1);
    $this->actingAs($buyer)->getJson('/api/v1/tickets')->assertOk()->assertJsonPath('data.counts.resolved', 1);
});

test('the ticket emails render without blade errors', function () {
    [$seller, , $product] = sellerWithProduct();
    $buyer = User::factory()->create(['name' => 'Buyer One']);
    $ticket = Ticket::create([
        'reference' => 'TKT-RENDER-1', 'user_id' => $buyer->id, 'seller_id' => $seller->id,
        'subject' => 'Is this available?', 'category' => 'product', 'product_id' => $product->id, 'last_message_at' => now(),
    ]);

    $toShop = renderMail((new TicketOpenedNotification($ticket, 'Do you have it in blue?', 'shop'))->toMail($seller));
    $toCustomer = renderMail((new TicketOpenedNotification($ticket, 'Do you have it in blue?', 'customer'))->toMail($buyer));
    $reply = renderMail((new TicketRepliedNotification($ticket, 'Yes we do.', 'Seller One'))->toMail($buyer));

    expect($toShop)->toContain('TKT-RENDER-1')->toContain('Do you have it in blue?')
        ->and($toCustomer)->toContain('TKT-RENDER-1')
        ->and($reply)->toContain('Seller One')->toContain('Yes we do.');
});

test('a password reset email links to the admin panel for admins and the storefront for customers', function () {
    config(['app.frontend_url' => 'https://antenkayume.com', 'app.admin_url' => 'https://admin.antenkayume.com']);
    $admin = User::factory()->create(['role' => 'master', 'email' => 'boss@example.com']);
    $customer = User::factory()->create(['email' => 'shopper@example.com']);

    $adminMail = renderMail((new ResetPasswordNotification('tok-admin'))->toMail($admin));
    $customerMail = renderMail((new ResetPasswordNotification('tok-customer'))->toMail($customer));

    expect($adminMail)->toContain('https://admin.antenkayume.com/reset-password?token=tok-admin')
        ->and($adminMail)->not->toContain('localhost')
        ->and($customerMail)->toContain('https://antenkayume.com/reset-password?token=tok-customer')
        ->and($customerMail)->not->toContain('admin.antenkayume.com');
});

test('an administrator can request a password reset', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin', 'email' => 'boss@example.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'boss@example.com'])->assertOk();

    Notification::assertSentTo($admin, ResetPasswordNotification::class);
});

test('an administrator can complete a password reset and sign in with the new password', function () {
    $admin = User::factory()->create(['role' => 'master', 'email' => 'boss@example.com', 'password' => 'old-password-123']);
    $token = Password::createToken($admin);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'boss@example.com',
        'password' => 'brand-new-pass-9',
        'password_confirmation' => 'brand-new-pass-9',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.com', 'password' => 'old-password-123'])->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.com', 'password' => 'brand-new-pass-9'])
        ->assertOk()
        ->assertJsonPath('data.user.role', 'master');
});

test('no outgoing email links to localhost once the public urls are configured', function () {
    config(['app.frontend_url' => 'https://antenkayume.com', 'app.admin_url' => 'https://admin.antenkayume.com']);
    $buyer = User::factory()->create(['name' => 'Buyer One']);
    $seller = User::factory()->create(['role' => 'seller', 'name' => 'Seller One']);
    $product = Product::create(['name' => 'Jersey', 'slug' => 'jersey-urls', 'price' => 50000, 'stock' => 4, 'seller_id' => $seller->id]);
    $order = Order::create(['number' => 'ANA-URL-1', 'user_id' => $buyer->id, 'subtotal' => 50000, 'total' => 50000, 'status' => 'pending', 'shipping_details' => ['fullName' => 'Buyer One'], 'delivery_latitude' => -6.79, 'delivery_longitude' => 39.2]);
    $order->items()->create(['product_id' => $product->id, 'seller_id' => $seller->id, 'name' => 'Jersey', 'unit_price' => 50000, 'quantity' => 1]);
    $order->load('items');
    $ticket = Ticket::create(['reference' => 'TKT-URL-1', 'user_id' => $buyer->id, 'seller_id' => $seller->id, 'subject' => 'Hello', 'last_message_at' => now()]);

    $rendered = [
        renderMail((new OrderPlacedNotification($order))->toMail($buyer)),
        renderMail((new NewOrderForSellerNotification($order, $order->items))->toMail($seller)),
        renderMail((new OrderStatusUpdatedNotification($order, 'shipped'))->toMail($buyer)),
        renderMail((new OrderPaymentConfirmedNotification($order))->toMail($buyer)),
        renderMail((new TicketOpenedNotification($ticket, 'hi', 'shop'))->toMail($seller)),
        renderMail((new TicketRepliedNotification($ticket, 'hi', 'Seller One'))->toMail($buyer)),
        renderMail((new ResetPasswordNotification('tok'))->toMail($buyer)),
    ];

    foreach ($rendered as $index => $html) {
        expect($html)->not->toContain('localhost', "email #{$index} still links to localhost");
    }
});

/** A paid mobile money order split across two shops. */
function escrowOrder(User $buyer, User $sellerA, ?User $sellerB = null, string $number = 'ANA-ESC-1'): Order
{
    $order = Order::create([
        'number' => $number, 'user_id' => $buyer->id, 'subtotal' => 100000, 'total' => 100000,
        'status' => 'pending', 'shipping_details' => [], 'payment_method' => 'mobile_money',
        'payment_status' => 'paid', 'paid_amount' => 100000, 'paid_at' => now(),
    ]);
    $order->items()->create(['seller_id' => $sellerA->id, 'name' => 'A item', 'unit_price' => 60000, 'quantity' => 1]);
    if ($sellerB) {
        $order->items()->create(['seller_id' => $sellerB->id, 'name' => 'B item', 'unit_price' => 40000, 'quantity' => 1]);
    }

    return $order->fresh();
}

test('paying for an order opens one escrow holding per shop with commission applied', function () {
    Setting::putGeneral(['commissionRate' => 10]);
    $buyer = User::factory()->create();
    $sellerA = User::factory()->create(['role' => 'seller']);
    $sellerB = User::factory()->create(['role' => 'seller']);
    $order = escrowOrder($buyer, $sellerA, $sellerB);

    app(EscrowService::class)->openForOrder($order);

    $a = EscrowHolding::where('seller_id', $sellerA->id)->firstOrFail();
    $b = EscrowHolding::where('seller_id', $sellerB->id)->firstOrFail();

    expect(EscrowHolding::count())->toBe(2)
        ->and((float) $a->gross_amount)->toBe(60000.0)
        ->and((float) $a->commission_amount)->toBe(6000.0)
        ->and((float) $a->net_amount)->toBe(54000.0)
        ->and((float) $b->net_amount)->toBe(36000.0)
        ->and($a->status)->toBe('held')
        ->and($a->reference)->toStartWith('ESC-');
});

test('opening escrow twice for the same order does not double the sellers balance', function () {
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $order = escrowOrder($buyer, $seller);
    $escrow = app(EscrowService::class);

    $escrow->openForOrder($order);
    $escrow->openForOrder($order->fresh());

    expect(EscrowHolding::where('order_id', $order->id)->count())->toBe(1);
});

test('cash on delivery orders are never escrowed', function () {
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $order = Order::create(['number' => 'ANA-COD-1', 'user_id' => $buyer->id, 'subtotal' => 5000, 'total' => 5000, 'status' => 'delivered', 'shipping_details' => [], 'payment_method' => 'cash_on_delivery', 'payment_status' => 'paid', 'paid_at' => now()]);
    $order->items()->create(['seller_id' => $seller->id, 'name' => 'x', 'unit_price' => 5000, 'quantity' => 1]);

    app(EscrowService::class)->openForOrder($order->fresh());

    expect(EscrowHolding::count())->toBe(0);
});

test('delivery starts the inspection window and the timer releases the money', function () {
    Setting::putGeneral(['escrowHoldingDays' => 3, 'commissionRate' => 0]);
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $order = escrowOrder($buyer, $seller);
    $escrow = app(EscrowService::class);
    $escrow->openForOrder($order);

    $escrow->markDelivered($order, $seller->id);
    $holding = EscrowHolding::firstOrFail();
    expect($holding->status)->toBe('pending_release')
        ->and($holding->releasable_at->isFuture())->toBeTrue();

    // Nothing is due yet.
    expect($escrow->releaseDue())->toBe(0);

    $this->travel(4)->days();
    expect($escrow->releaseDue())->toBe(1)
        ->and($holding->refresh()->status)->toBe('released')
        ->and($holding->release_reason)->toBe('auto');
});

test('a buyer can confirm receipt to release the money immediately', function () {
    Setting::putGeneral(['commissionRate' => 0]);
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $order = escrowOrder($buyer, $seller);
    app(EscrowService::class)->openForOrder($order);
    $holding = EscrowHolding::firstOrFail();

    $this->actingAs($buyer)->postJson("/api/v1/escrow/{$holding->id}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'released');

    expect($holding->refresh()->release_reason)->toBe('buyer_confirmed');
});

test('a disputed holding is frozen and is skipped by the auto release', function () {
    Setting::putGeneral(['escrowHoldingDays' => 1]);
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $order = escrowOrder($buyer, $seller);
    $escrow = app(EscrowService::class);
    $escrow->openForOrder($order);
    $escrow->markDelivered($order, $seller->id);
    $holding = EscrowHolding::firstOrFail();

    $this->actingAs($buyer)->postJson("/api/v1/escrow/{$holding->id}/dispute", ['reason' => 'The item arrived broken.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'disputed');

    $this->travel(5)->days();
    expect($escrow->releaseDue())->toBe(0)
        ->and($holding->refresh()->status)->toBe('disputed');
});

test('only an administrator can settle a dispute', function () {
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $admin = User::factory()->create(['role' => 'admin']);
    $order = escrowOrder($buyer, $seller);
    $escrow = app(EscrowService::class);
    $escrow->openForOrder($order);
    $holding = EscrowHolding::firstOrFail();
    $escrow->dispute($holding, 'broken');

    $this->actingAs($seller)->postJson("/api/v1/admin/escrow/{$holding->id}/resolve", ['outcome' => 'release'])->assertStatus(403);
    $this->actingAs($buyer)->postJson("/api/v1/admin/escrow/{$holding->id}/resolve", ['outcome' => 'release'])->assertStatus(403);

    $this->actingAs($admin)->postJson("/api/v1/admin/escrow/{$holding->id}/resolve", ['outcome' => 'refund', 'note' => 'Item was faulty'])
        ->assertOk()
        ->assertJsonPath('data.status', 'refunded');

    expect($holding->refresh()->refunded_at)->not->toBeNull();
});

test('a seller cannot release their own escrowed money', function () {
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $order = escrowOrder($buyer, $seller);
    app(EscrowService::class)->openForOrder($order);
    $holding = EscrowHolding::firstOrFail();

    // confirm is a buyer action; the seller is not the order's customer.
    $this->actingAs($seller)->postJson("/api/v1/escrow/{$holding->id}/confirm")->assertNotFound();

    expect($holding->refresh()->status)->toBe('held');
});

test('another customer cannot confirm or dispute someone elses escrow', function () {
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $order = escrowOrder($buyer, $seller);
    app(EscrowService::class)->openForOrder($order);
    $holding = EscrowHolding::firstOrFail();

    $this->actingAs($stranger)->postJson("/api/v1/escrow/{$holding->id}/confirm")->assertNotFound();
    $this->actingAs($stranger)->postJson("/api/v1/escrow/{$holding->id}/dispute", ['reason' => 'x'])->assertNotFound();
    $this->actingAs($stranger)->getJson('/api/v1/escrow')->assertOk()->assertJsonPath('data.meta.total', 0);

    expect($holding->refresh()->status)->toBe('held');
});

test('a seller balance moves from held to available to paid out', function () {
    Setting::putGeneral(['commissionRate' => 10, 'escrowHoldingDays' => 0]);
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $admin = User::factory()->create(['role' => 'admin']);
    $order = escrowOrder($buyer, $seller);
    $escrow = app(EscrowService::class);
    $escrow->openForOrder($order);

    expect($escrow->balanceFor($seller->id)['heldBalance'])->toBe(54000.0)
        ->and($escrow->balanceFor($seller->id)['availableBalance'])->toBe(0.0);

    $escrow->markDelivered($order, $seller->id);
    $escrow->releaseDue();

    expect($escrow->balanceFor($seller->id)['availableBalance'])->toBe(54000.0);

    $payout = $this->actingAs($admin)->postJson('/api/v1/admin/payouts', ['sellerId' => $seller->id, 'method' => 'mobile_money', 'destination' => '255712345678'])
        ->assertCreated()->json('data');
    expect((float) $payout['amount'])->toBe(54000.0)
        ->and($escrow->balanceFor($seller->id)['availableBalance'])->toBe(0.0);

    $this->actingAs($admin)->patchJson("/api/v1/admin/payouts/{$payout['id']}", ['status' => 'paid'])
        ->assertOk()->assertJsonPath('data.status', 'paid');

    expect(EscrowHolding::firstOrFail()->status)->toBe('paid')
        ->and($escrow->balanceFor($seller->id)['paidOut'])->toBe(54000.0);
});

test('cancelling a payout returns the money to the sellers available balance', function () {
    Setting::putGeneral(['commissionRate' => 0, 'escrowHoldingDays' => 0]);
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $admin = User::factory()->create(['role' => 'admin']);
    $order = escrowOrder($buyer, $seller);
    $escrow = app(EscrowService::class);
    $escrow->openForOrder($order);
    $escrow->markDelivered($order, $seller->id);
    $escrow->releaseDue();

    $payout = $this->actingAs($admin)->postJson('/api/v1/admin/payouts', ['sellerId' => $seller->id])->assertCreated()->json('data');
    $this->actingAs($admin)->patchJson("/api/v1/admin/payouts/{$payout['id']}", ['status' => 'cancelled'])->assertOk();

    expect($escrow->balanceFor($seller->id)['availableBalance'])->toBe(60000.0)
        ->and(EscrowHolding::firstOrFail()->payout_id)->toBeNull();
});

test('a paid payout cannot be cancelled', function () {
    Setting::putGeneral(['escrowHoldingDays' => 0]);
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $admin = User::factory()->create(['role' => 'admin']);
    $order = escrowOrder($buyer, $seller);
    $escrow = app(EscrowService::class);
    $escrow->openForOrder($order);
    $escrow->markDelivered($order, $seller->id);
    $escrow->releaseDue();
    $payout = $this->actingAs($admin)->postJson('/api/v1/admin/payouts', ['sellerId' => $seller->id])->assertCreated()->json('data');
    $this->actingAs($admin)->patchJson("/api/v1/admin/payouts/{$payout['id']}", ['status' => 'paid'])->assertOk();

    $this->actingAs($admin)->patchJson("/api/v1/admin/payouts/{$payout['id']}", ['status' => 'cancelled'])->assertStatus(422);
});

test('a seller sees only their own escrow and a buyer never sees the commission', function () {
    Setting::putGeneral(['commissionRate' => 10]);
    $buyer = User::factory()->create();
    $sellerA = User::factory()->create(['role' => 'seller']);
    $sellerB = User::factory()->create(['role' => 'seller']);
    $order = escrowOrder($buyer, $sellerA, $sellerB);
    app(EscrowService::class)->openForOrder($order);

    $this->actingAs($sellerA)->getJson('/api/v1/escrow')->assertOk()->assertJsonPath('data.meta.total', 1);

    $buyerView = $this->actingAs($buyer)->getJson('/api/v1/escrow')->assertOk();
    $buyerView->assertJsonPath('data.meta.total', 2)
        ->assertJsonPath('data.holdings.0.commissionAmount', null)
        ->assertJsonPath('data.holdings.0.netAmount', null);
});

test('escrow can be switched off by the master admin', function () {
    Setting::putGeneral(['escrowEnabled' => false]);
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $order = escrowOrder($buyer, $seller);

    app(EscrowService::class)->openForOrder($order);

    expect(EscrowHolding::count())->toBe(0);
});

test('a mobile money webhook opens escrow for the order automatically', function () {
    Notification::fake();
    Setting::putGeneral(['commissionRate' => 5]);
    config(['services.malipopay.webhook_secret' => 'shhh']);
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $order = Order::create(['number' => 'ANA-HOOK-1', 'user_id' => $buyer->id, 'subtotal' => 20000, 'total' => 20000, 'status' => 'pending', 'shipping_details' => [], 'payment_method' => 'mobile_money', 'payment_status' => 'processing', 'payment_reference' => 'ML-HOOK-1']);
    $order->items()->create(['seller_id' => $seller->id, 'name' => 'x', 'unit_price' => 20000, 'quantity' => 1]);

    [$body, $server] = malipoWebhook(['event' => 'payment.confirmed', 'status' => 'SUCCESSFUL', 'customerReference' => 'ANA-HOOK-1', 'amount' => 20000]);
    $this->call('POST', '/api/v1/webhooks/malipopay', [], [], [], $server, $body)->assertOk();

    $holding = EscrowHolding::firstOrFail();
    expect($holding->status)->toBe('held')
        ->and((float) $holding->net_amount)->toBe(19000.0);
});

/** @return array<string, mixed> */
function applicationPayload(array $overrides = []): array
{
    return [...[
        'fullName' => 'Asha Mwinyi',
        'businessName' => 'Asha Fashions',
        'productCategory' => 'Clothing and shoes',
        'phone' => '0712345678',
        'region' => 'Dar es Salaam',
        'city' => 'Kinondoni',
        'streetAddress' => 'Mikocheni B, plot 42',
        'businessDescription' => 'We sell womens clothing imported from Dubai.',
        'tinNumber' => '123-456-789',
        'payoutMethod' => 'mobile_money',
        'payoutAccountName' => 'Asha Mwinyi',
        'payoutNumber' => '0712345678',
        'idDocumentType' => 'nida',
        'idNumber' => '19900101-12345-00001-23',
        'idDocumentPath' => 'seller-applications/1/id.jpg',
        'acceptTerms' => true,
    ], ...$overrides];
}

test('a customer can apply to become a seller and sees pending approval', function () {
    Notification::fake();
    User::factory()->create(['role' => 'master']);
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.statusLabel', 'Pending Approval')
        ->assertJsonPath('data.businessName', 'Asha Fashions');

    $application = SellerApplication::firstOrFail();
    expect($application->reference)->toStartWith('APP-')
        ->and($application->user_id)->toBe($buyer->id)
        ->and($application->terms_accepted_at)->not->toBeNull()
        // The applicant is still a customer until an admin approves.
        ->and($buyer->refresh()->role)->toBe('user');

    $this->actingAs($buyer)->getJson('/api/v1/seller-application')->assertOk()->assertJsonPath('data.status', 'pending');
});

test('the application requires the terms to be accepted and the identity document', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload(['acceptTerms' => false]))
        ->assertStatus(422)->assertJsonValidationErrors('acceptTerms');

    $payload = applicationPayload();
    unset($payload['idDocumentPath'], $payload['idNumber']);
    $this->actingAs($buyer)->postJson('/api/v1/seller-application', $payload)
        ->assertStatus(422)->assertJsonValidationErrors(['idDocumentPath', 'idNumber']);

    expect(SellerApplication::count())->toBe(0);
});

test('a customer cannot submit a second application while one is pending', function () {
    Notification::fake();
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload())->assertCreated();

    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload())->assertStatus(422);

    expect(SellerApplication::count())->toBe(1);
});

test('an existing seller cannot apply again', function () {
    $seller = User::factory()->create(['role' => 'seller']);

    $this->actingAs($seller)->postJson('/api/v1/seller-application', applicationPayload())->assertStatus(422);
});

test('approving an application turns the account into a seller and opens their shop', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload())->assertCreated();
    $application = SellerApplication::firstOrFail();

    $this->actingAs($admin)->postJson("/api/v1/admin/seller-applications/{$application->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect($buyer->refresh()->role)->toBe('seller')
        ->and(Shop::where('seller_id', $buyer->id)->value('name'))->toBe('Asha Fashions')
        ->and($application->refresh()->reviewed_by)->toBe($admin->id);

    // The newly promoted seller can now reach the seller dashboard.
    $this->actingAs($buyer->refresh())->getJson('/api/v1/seller/dashboard')->assertOk();

    Notification::assertSentTo($buyer, SellerApplicationStatusNotification::class);
});

test('rejecting an application records the reason and leaves the account a customer', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload())->assertCreated();
    $application = SellerApplication::firstOrFail();

    $this->actingAs($admin)->postJson("/api/v1/admin/seller-applications/{$application->id}/reject", ['reason' => 'The ID photo is unreadable.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect($buyer->refresh()->role)->toBe('user')
        ->and($application->refresh()->rejection_reason)->toBe('The ID photo is unreadable.');

    // Rejection is not a dead end: the applicant may try again.
    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload())->assertCreated();
});

test('requesting more information lets the applicant edit and resubmit the same application', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload())->assertCreated();
    $application = SellerApplication::firstOrFail();

    $this->actingAs($admin)->postJson("/api/v1/admin/seller-applications/{$application->id}/request-information", ['notes' => 'Please send a clearer photo of your NIDA.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'more_info_requested');

    $this->actingAs($buyer)->getJson('/api/v1/seller-application')
        ->assertOk()
        ->assertJsonPath('data.canEdit', true)
        ->assertJsonPath('data.reviewNotes', 'Please send a clearer photo of your NIDA.');

    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload(['businessName' => 'Asha Fashions Ltd']))->assertCreated();

    // Updated in place rather than duplicated.
    expect(SellerApplication::count())->toBe(1)
        ->and($application->refresh()->status)->toBe('pending')
        ->and($application->business_name)->toBe('Asha Fashions Ltd')
        ->and($application->review_notes)->toBeNull();
});

test('only administrators can review applications', function () {
    Notification::fake();
    $buyer = User::factory()->create();
    $seller = User::factory()->create(['role' => 'seller']);
    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload())->assertCreated();
    $application = SellerApplication::firstOrFail();

    foreach ([$buyer, $seller] as $actor) {
        $this->actingAs($actor)->getJson('/api/v1/admin/seller-applications')->assertStatus(403);
        $this->actingAs($actor)->postJson("/api/v1/admin/seller-applications/{$application->id}/approve")->assertStatus(403);
    }

    expect($buyer->refresh()->role)->toBe('user');
});

test('identity documents are stored privately and only streamed to administrators', function () {
    Storage::fake('local');
    Storage::fake('public');
    $admin = User::factory()->create(['role' => 'admin']);
    $buyer = User::factory()->create();

    $upload = $this->actingAs($buyer)->postJson('/api/v1/seller-application/documents', [
        'kind' => 'id_document',
        'file' => UploadedFile::fake()->create('nida.jpg', 120, 'image/jpeg'),
    ])->assertCreated()->json('data');

    // A private document is never handed back as a public URL.
    expect($upload['url'])->toBeNull()
        ->and($upload['path'])->toStartWith('seller-applications/');
    Storage::disk('local')->assertExists($upload['path']);
    Storage::disk('public')->assertMissing($upload['path']);

    $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload(['idDocumentPath' => $upload['path']]))->assertCreated();
    $application = SellerApplication::firstOrFail();

    $this->actingAs($admin)->get("/api/v1/admin/seller-applications/{$application->id}/document/id_document")->assertOk();
    $this->actingAs($buyer)->get("/api/v1/admin/seller-applications/{$application->id}/document/id_document")->assertStatus(403);
});

test('a shop logo is uploaded to the public disk for branding', function () {
    Storage::fake('public');
    $buyer = User::factory()->create();

    $upload = $this->actingAs($buyer)->postJson('/api/v1/seller-application/documents', [
        'kind' => 'logo',
        'file' => UploadedFile::fake()->create('logo.png', 120, 'image/png'),
    ])->assertCreated()->json('data');

    expect($upload['url'])->not->toBeNull();
    Storage::disk('public')->assertExists($upload['path']);
});

test('the admin queue reports how many applications are pending', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    foreach (range(1, 3) as $i) {
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->postJson('/api/v1/seller-application', applicationPayload(['businessName' => "Shop {$i}"]))->assertCreated();
    }

    $this->actingAs($admin)->getJson('/api/v1/admin/seller-applications?status=pending')
        ->assertOk()
        ->assertJsonPath('data.counts.pending', 3)
        ->assertJsonPath('data.meta.total', 3);

    $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('data.pendingSellerApplicationsCount', 3);
});

test('the seller application emails render without blade errors', function () {
    $admin = User::factory()->create(['role' => 'master']);
    $buyer = User::factory()->create(['name' => 'Asha Mwinyi']);
    $application = SellerApplication::create([
        'reference' => 'APP-RENDER-1', 'user_id' => $buyer->id, 'full_name' => 'Asha Mwinyi',
        'business_name' => 'Asha Fashions', 'product_category' => 'Clothing', 'phone' => '0712345678',
        'region' => 'Dar es Salaam', 'city' => 'Kinondoni', 'street_address' => 'Mikocheni',
        'business_description' => 'Clothes', 'payout_account_name' => 'Asha', 'payout_number' => '0712345678',
        'id_number' => 'X', 'id_document_path' => 'x.jpg', 'review_notes' => 'Send a clearer NIDA photo.',
        'rejection_reason' => 'Unreadable document.',
    ]);

    foreach (['submitted', 'approved', 'rejected', 'more_info'] as $event) {
        $rendered = renderMail((new SellerApplicationStatusNotification($application, $event))->toMail($buyer));
        expect($rendered)->toContain('APP-RENDER-1')->toContain('Asha Fashions');
    }

    $alert = renderMail((new SellerApplicationStatusNotification($application, 'admin_alert'))->toMail($admin));
    expect($alert)->toContain('Asha Fashions')->toContain($buyer->email);
});

test('an administrator can set a category icon and shoppers receive it', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);

    $created = $this->postJson('/api/v1/admin/categories', ['name' => 'Electronics', 'description' => 'Phones and gadgets', 'icon' => 'Phone'])
        ->assertCreated()
        ->assertJsonPath('data.icon', 'Phone')
        ->json('data');

    // The stored value is the icon's export name, not a glyph.
    $this->patchJson("/api/v1/admin/categories/{$created['id']}", ['icon' => 'WrenchAdjustable'])
        ->assertOk()
        ->assertJsonPath('data.icon', 'WrenchAdjustable');

    $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.icon', 'WrenchAdjustable');

    // Clearing the icon is allowed; the storefront falls back to an image.
    $this->patchJson("/api/v1/admin/categories/{$created['id']}", ['icon' => null])
        ->assertOk()
        ->assertJsonPath('data.icon', null);
});

test('a category can be created with a parent without breaking the insert', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);
    $parent = Category::create(['name' => 'Fashion', 'slug' => 'fashion']);

    $this->postJson('/api/v1/admin/categories', ['name' => 'Shoes', 'parentId' => $parent->id, 'icon' => 'Bag'])
        ->assertCreated()
        ->assertJsonPath('data.parentId', (string) $parent->id)
        ->assertJsonPath('data.icon', 'Bag');
});
