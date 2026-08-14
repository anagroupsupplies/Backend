<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebaseTokenVerifier
{
    /** @return array<string, mixed> */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed Firebase token.');
        }

        $header = $this->decode($parts[0]);
        $claims = $this->decode($parts[1]);
        $projectId = (string) config('services.firebase.project_id');
        $now = time();

        if (($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) {
            throw new RuntimeException('Unsupported Firebase token.');
        }

        if (($claims['aud'] ?? null) !== $projectId) {
            throw new RuntimeException("Token audience \"{$claims['aud']}\" does not match configured Firebase project \"{$projectId}\".");
        }
        if (($claims['iss'] ?? null) !== "https://securetoken.google.com/{$projectId}") {
            throw new RuntimeException("Token issuer \"{$claims['iss']}\" does not match configured Firebase project \"{$projectId}\".");
        }
        if (empty($claims['sub'])) {
            throw new RuntimeException('Token is missing a subject (sub) claim.');
        }
        if (($claims['exp'] ?? 0) < $now) {
            throw new RuntimeException('Token has expired.');
        }
        if (($claims['iat'] ?? PHP_INT_MAX) > $now + 60) {
            throw new RuntimeException('Token issued-at time is too far in the future (server clock skew?).');
        }

        $certificates = Cache::remember('firebase-public-certificates', now()->addMinutes(30), fn (): array => $this->fetchCertificates());
        $certificate = $certificates[$header['kid']] ?? null;

        if (! $certificate) {
            Cache::forget('firebase-public-certificates');
            $certificates = $this->fetchCertificates();
            Cache::put('firebase-public-certificates', $certificates, now()->addMinutes(30));
            $certificate = $certificates[$header['kid']] ?? null;
        }

        if (! $certificate || openssl_verify("{$parts[0]}.{$parts[1]}", $this->base64UrlDecode($parts[2]), $certificate, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Firebase token signature is invalid.');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($this->base64UrlDecode($value), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Malformed Firebase token payload.');
        }

        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        $base64 = strtr($value, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            throw new RuntimeException('Malformed Firebase token encoding.');
        }

        return $decoded;
    }

    /** @return array<string, string> */
    private function fetchCertificates(): array
    {
        return Http::timeout(10)
            ->get('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com')
            ->throw()
            ->json();
    }
}
