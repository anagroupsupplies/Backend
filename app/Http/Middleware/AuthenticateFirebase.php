<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\FirebaseTokenVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateFirebase
{
    public function __construct(private readonly FirebaseTokenVerifier $verifier) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }

        if (str_starts_with($token, 'ana_')) {
            return $this->authenticateApiToken($request, $next, $token);
        }

        try {
            $claims = $this->verifier->verify($token);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The authentication token is invalid or expired.',
                'reason' => config('app.debug') ? $exception->getMessage() : null,
            ], 401);
        }

        $email = $claims['email'] ?? "{$claims['sub']}@firebase.local";
        $user = User::firstOrNew(['firebase_uid' => $claims['sub']]);
        $isNewUser = ! $user->exists;
        $user->fill([
            'email' => $email,
            'name' => $claims['name'] ?? $email,
            'photo_url' => $claims['picture'] ?? null,
            'email_verified_at' => ($claims['email_verified'] ?? false) ? now() : null,
            'last_login_at' => now(),
        ]);
        if ($isNewUser) {
            $user->is_active = true;
            $user->role = 'user';
        }
        if (in_array(strtolower($email), array_map('strtolower', config('services.firebase.master_admin_emails')), true)) {
            $user->role = 'master';
        } elseif ($isNewUser && in_array(strtolower($email), array_map('strtolower', config('services.firebase.admin_emails')), true)) {
            $user->role = 'admin';
        }
        $user->save();

        if (! $user->is_active) {
            return response()->json(['message' => 'This account is inactive.'], 403);
        }

        auth()->setUser($user);
        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }

    private function authenticateApiToken(Request $request, Closure $next, string $plainTextToken): Response
    {
        $token = ApiToken::with('user')->where('token_hash', hash('sha256', $plainTextToken))->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json(['message' => 'The authentication token is invalid or expired.'], 401);
        }

        if (! $token->user->is_active) {
            return response()->json(['message' => 'This account is inactive.'], 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        auth()->setUser($token->user);
        $request->setUserResolver(fn (): User => $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
