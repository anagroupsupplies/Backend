<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'remember' => ['nullable', 'boolean'],
        ]);
        $email = strtolower($data['email']);
        $user = User::create([
            'name' => $data['name'] ?? Str::before($email, '@'),
            'email' => $email,
            'password' => $data['password'],
            'role' => in_array($email, array_map('strtolower', config('services.firebase.master_admin_emails')), true)
                ? 'master'
                : (in_array($email, array_map('strtolower', config('services.firebase.admin_emails')), true) ? 'admin' : 'user'),
            'is_active' => true,
            'last_login_at' => now(),
        ]);

        return response()->json(['data' => $this->authenticationData($user, $data['remember'] ?? false)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);
        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided email or password is incorrect.']]);
        }
        if (! $user->is_active) {
            return response()->json(['message' => 'This account is inactive.'], 403);
        }
        $user->update(['last_login_at' => now()]);

        return response()->json(['data' => $this->authenticationData($user, $data['remember'] ?? false)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('api_token')?->delete();

        return response()->json(['message' => 'Signed out successfully.']);
    }

    public function session(Request $request): JsonResponse
    {
        return $this->me($request);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userData($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'preferences' => ['sometimes', 'array'],
        ]);
        $request->user()->update($data);

        return $this->me($request);
    }

    /** @return array<string, mixed> */
    private function userData(Request $request): array
    {
        $user = $request->user();

        return ['id' => $user->id, 'uid' => $user->firebase_uid ?? "local:{$user->id}", 'email' => $user->email, 'displayName' => $user->name, 'photoURL' => $user->photo_url, 'phone' => $user->phone, 'role' => $user->role, 'isAdmin' => $user->isAdmin(), 'isMaster' => $user->isMaster(), 'isActive' => $user->is_active, 'preferences' => $user->preferences, 'createdAt' => $user->created_at];
    }

    /** @return array{token: string, user: array<string, mixed>} */
    private function authenticationData(User $user, bool $remember): array
    {
        $plainTextToken = 'ana_'.Str::random(64);
        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => $remember ? now()->addYear() : now()->addDays(30),
        ]);

        $request = Request::create('/');
        $request->setUserResolver(fn (): User => $user);

        return ['token' => $plainTextToken, 'user' => $this->userData($request)];
    }
}
