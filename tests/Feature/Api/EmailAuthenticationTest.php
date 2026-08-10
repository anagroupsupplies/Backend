<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

test('local registration sends an email verification notification', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'New Customer',
        'email' => 'customer@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()->assertJsonPath('data.user.emailVerified', false);
    Notification::assertSentTo(User::whereEmail('customer@example.com')->first(), VerifyEmailNotification::class);
});

test('a valid signed link verifies the user email', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'user' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->get($url)->assertRedirect(config('app.frontend_url').'/email-verified?status=success');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a user can request and complete a password reset', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => 'old-password']);
    $token = null;

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email,
        'token' => $token,
        'password' => 'new-password123',
        'password_confirmation' => 'new-password123',
    ])->assertOk();

    expect(Hash::check('new-password123', $user->fresh()->password))->toBeTrue();
});

test('authentication emails use the Antenkayume branded templates', function () {
    $user = User::factory()->make(['name' => 'Antenka Customer']);
    $verification = view('emails.verify-email', ['customer' => $user, 'url' => 'https://example.com/verify'])->render();
    $reset = view('emails.reset-password', ['customer' => $user, 'url' => 'https://example.com/reset', 'expires' => 60])->render();

    expect($verification)->toContain('Antenkayume')->toContain('Verify my email')
        ->and($reset)->toContain('Antenkayume')->toContain('Choose a new password')
        ->and($verification)->not->toContain('Laravel');
});
