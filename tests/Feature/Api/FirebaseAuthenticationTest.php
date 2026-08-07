<?php

use App\Models\User;
use App\Services\FirebaseTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a verified firebase user is persisted to mysql and authenticated', function () {
    config(['services.firebase.admin_emails' => ['admin@example.com']]);

    $verifier = Mockery::mock(FirebaseTokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('valid-firebase-token')
        ->andReturn([
            'sub' => 'firebase-user-123',
            'email' => 'admin@example.com',
            'name' => 'Shop Admin',
            'picture' => 'https://example.com/avatar.jpg',
            'email_verified' => true,
        ]);
    app()->instance(FirebaseTokenVerifier::class, $verifier);

    $this->withToken('valid-firebase-token')
        ->postJson('/api/v1/auth/session')
        ->assertOk()
        ->assertJsonPath('data.uid', 'firebase-user-123')
        ->assertJsonPath('data.email', 'admin@example.com')
        ->assertJsonPath('data.isAdmin', true);

    $user = User::where('firebase_uid', 'firebase-user-123')->firstOrFail();
    expect($user->name)->toBe('Shop Admin')
        ->and($user->role)->toBe('admin')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('an invalid firebase token cannot create a mysql user', function () {
    $verifier = Mockery::mock(FirebaseTokenVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new RuntimeException('Invalid token'));
    app()->instance(FirebaseTokenVerifier::class, $verifier);

    $this->withToken('invalid-token')->postJson('/api/v1/auth/session')->assertUnauthorized();

    expect(User::count())->toBe(0);
});
