<?php

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('a customer can register with credentials stored securely in mysql', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Local Customer',
        'email' => 'customer@example.com',
        'password' => 'strong-password',
        'password_confirmation' => 'strong-password',
    ])->assertCreated()
        ->assertJsonPath('data.user.email', 'customer@example.com')
        ->assertJsonPath('data.user.uid', 'local:1');

    $plainTextToken = $response->json('data.token');
    $user = User::where('email', 'customer@example.com')->firstOrFail();

    expect($user->firebase_uid)->toBeNull()
        ->and(Hash::check('strong-password', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('strong-password')
        ->and(ApiToken::where('token_hash', hash('sha256', $plainTextToken))->exists())->toBeTrue();

    $this->withToken($plainTextToken)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'customer@example.com');
});

test('a customer can login and revoke the current token on logout', function () {
    User::create([
        'name' => 'Customer',
        'email' => 'customer@example.com',
        'password' => 'strong-password',
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'strong-password',
    ])->assertOk();
    $plainTextToken = $response->json('data.token');

    $this->withToken($plainTextToken)->postJson('/api/v1/auth/logout')->assertOk();
    $this->withToken($plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});

test('invalid local credentials do not issue a token', function () {
    User::create(['name' => 'Customer', 'email' => 'customer@example.com', 'password' => 'correct-password', 'is_active' => true]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    expect(ApiToken::count())->toBe(0);
});
