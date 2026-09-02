<?php

use App\Http\Middleware\AuthenticateFirebase;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\AutoFayaSmsService;
use App\Services\OrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(AuthenticateFirebase::class);
    // Emails are covered elsewhere; these tests are only about the SMS leg.
    Notification::fake();
});

function enableSms(string $sender = 'ANTENKAYUME'): void
{
    config(['services.autofaya.api_key' => 'af_live_testkey', 'services.autofaya.base_url' => 'https://api.autofaya.com']);
    Setting::putGeneral(['smsEnabled' => true, 'smsSenderName' => $sender]);
}

function smsOrder(?string $phone = '0712345678'): Order
{
    $order = Order::create([
        'number' => 'ANA-SMS-1',
        'user_id' => User::factory()->create(['phone' => null])->id,
        'subtotal' => 100000,
        'total' => 100000,
        'status' => 'pending',
        'payment_method' => 'mobile_money',
        'payment_status' => 'pending',
        'shipping_details' => ['fullName' => 'Buyer One', 'email' => 'buyer@example.com', 'phone' => $phone],
    ]);

    return $order->fresh();
}

test('placing an order sends the buyer an SMS in the exact shape AutoFaya expects', function () {
    Http::fake(['api.autofaya.com/*' => Http::response(['status' => 'queued'], 200)]);
    enableSms();

    app(OrderNotifier::class)->orderPlaced(smsOrder());

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.autofaya.com/api/v1/messages'
            && $request->hasHeader('Authorization', 'Bearer af_live_testkey')
            && $request['sender_name'] === 'ANTENKAYUME'
            // A local 07... number is normalised to E.164 before it leaves us.
            && $request['phone_number'] === '+255712345678'
            && str_contains($request['message'], 'ANA-SMS-1');
    });
});

test('no SMS leaves the platform while the master switch is off', function () {
    Http::fake();
    config(['services.autofaya.api_key' => 'af_live_testkey']);
    Setting::putGeneral(['smsEnabled' => false]);

    app(OrderNotifier::class)->orderPlaced(smsOrder());

    Http::assertNothingSent();
});

test('no SMS is attempted when the API key is missing even if the switch is on', function () {
    Http::fake();
    config(['services.autofaya.api_key' => '']);
    Setting::putGeneral(['smsEnabled' => true]);

    app(OrderNotifier::class)->orderPlaced(smsOrder());

    Http::assertNothingSent();
});

test('phone numbers are normalised and unusable ones are rejected outright', function () {
    $sms = app(AutoFayaSmsService::class);

    expect($sms->normalisePhone('0712345678'))->toBe('+255712345678')
        ->and($sms->normalisePhone('255712345678'))->toBe('+255712345678')
        ->and($sms->normalisePhone('+255 712 345 678'))->toBe('+255712345678')
        ->and($sms->normalisePhone('712345678'))->toBe('+255712345678')
        // Landline prefix, too short, missing and nonsense all fail closed
        // rather than burning a message on a number that cannot work.
        ->and($sms->normalisePhone('0222123456'))->toBeNull()
        ->and($sms->normalisePhone('07123'))->toBeNull()
        ->and($sms->normalisePhone(null))->toBeNull()
        ->and($sms->normalisePhone('not a phone'))->toBeNull();
});

test('an SMS failure never breaks the order it was reporting on', function () {
    Http::fake(['api.autofaya.com/*' => Http::response(['message' => 'Insufficient balance'], 402)]);
    enableSms();

    // The notifier swallows it, so checkout and the payment webhook carry on.
    app(OrderNotifier::class)->orderPlaced(smsOrder());

    Http::assertSentCount(1);
});

test('only the delivery states a customer acts on trigger a status SMS', function () {
    Http::fake(['api.autofaya.com/*' => Http::response([], 200)]);
    enableSms();
    $notifier = app(OrderNotifier::class);
    $order = smsOrder();

    foreach (['processing', 'confirmed'] as $quiet) {
        $notifier->statusUpdated($order, $quiet);
    }
    Http::assertNothingSent();

    foreach (['shipped', 'delivered', 'cancelled'] as $loud) {
        $notifier->statusUpdated($order, $loud);
    }
    Http::assertSentCount(3);
});

test('an order with no phone number on it is simply skipped', function () {
    Http::fake();
    enableSms();

    app(OrderNotifier::class)->orderPlaced(smsOrder(phone: null));

    Http::assertNothingSent();
});

test('a master admin switches SMS on and names the sender, and it is audited', function () {
    $master = User::factory()->create(['role' => 'master']);

    $this->actingAs($master)
        ->patchJson('/api/v1/admin/settings/sms', ['enabled' => true, 'senderName' => 'ANTENKAY'])
        ->assertOk()
        ->assertJsonPath('data.smsEnabled', true)
        ->assertJsonPath('data.smsSenderName', 'ANTENKAY');

    expect(Setting::general()['smsEnabled'])->toBeTrue()
        ->and(AuditLog::where('action', 'settings.sms_updated')->exists())->toBeTrue();
});

test('sender names longer than an operator accepts are rejected', function () {
    $master = User::factory()->create(['role' => 'master']);

    $this->actingAs($master)
        ->patchJson('/api/v1/admin/settings/sms', ['senderName' => 'WAY TOO LONG A SENDER'])
        ->assertStatus(422);
});

test('an ordinary customer cannot touch the SMS settings', function () {
    $this->actingAs(User::factory()->create())
        ->patchJson('/api/v1/admin/settings/sms', ['enabled' => true])
        ->assertForbidden();
});

test('an admin can send a test message even before switching SMS on', function () {
    Http::fake(['api.autofaya.com/*' => Http::response([], 200)]);
    config(['services.autofaya.api_key' => 'af_live_testkey', 'services.autofaya.base_url' => 'https://api.autofaya.com']);
    Setting::putGeneral(['smsEnabled' => false, 'smsSenderName' => 'ANTENKAY']);
    $master = User::factory()->create(['role' => 'master']);

    $this->actingAs($master)
        ->postJson('/api/v1/admin/settings/sms/test', ['phone' => '0712345678'])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['phone_number'] === '+255712345678' && $request['sender_name'] === 'ANTENKAY');
});

test('the test send explains itself instead of failing silently', function () {
    Http::fake();
    config(['services.autofaya.api_key' => '']);
    $master = User::factory()->create(['role' => 'master']);

    // No credentials configured on the server.
    $this->actingAs($master)->postJson('/api/v1/admin/settings/sms/test', ['phone' => '0712345678'])->assertStatus(422);

    // Credentials present, but the number could never receive it.
    config(['services.autofaya.api_key' => 'af_live_testkey']);
    $this->actingAs($master)->postJson('/api/v1/admin/settings/sms/test', ['phone' => '123'])->assertStatus(422);

    Http::assertNothingSent();
});

test('the admin panel is told whether SMS is actually usable, not just switched on', function () {
    config(['services.autofaya.api_key' => '']);
    Setting::putGeneral(['smsEnabled' => true]);
    $master = User::factory()->create(['role' => 'master']);

    $this->actingAs($master)->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('data.smsEnabled', true)
        ->assertJsonPath('data.smsConfigured', false)
        ->assertJsonPath('data.smsAvailable', false);
});
