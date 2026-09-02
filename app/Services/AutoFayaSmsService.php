<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends SMS through AutoFaya.
 *
 * Every message costs money and cannot be recalled, so sending is opt-in: an
 * administrator must switch SMS on and the API key must be configured on the
 * server. The sender name is an admin setting rather than an env value because
 * it is branding the shop owner changes, not deployment configuration.
 */
class AutoFayaSmsService
{
    private const MESSAGES_PATH = '/api/v1/messages';

    /** A single SMS part; longer messages are split and billed per part. */
    public const SINGLE_PART_LENGTH = 160;

    public function isConfigured(): bool
    {
        return (string) config('services.autofaya.api_key') !== '';
    }

    /** The administrator's master switch for outgoing SMS. */
    public function isEnabled(): bool
    {
        return (bool) (Setting::general()['smsEnabled'] ?? false);
    }

    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    public function senderName(): string
    {
        $name = trim((string) (Setting::general()['smsSenderName'] ?? ''));

        return $name !== '' ? $name : 'AUTOFAYA';
    }

    /**
     * Normalise a Tanzanian number to the E.164 form the API expects
     * (`+255XXXXXXXXX`). Returns null when the input cannot be a valid number,
     * so callers never send a request that is certain to fail.
     */
    public function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '255')) {
            $national = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $national = $digits;
        } else {
            return null;
        }

        return preg_match('/^[67]\d{8}$/', $national) === 1 ? '+255'.$national : null;
    }

    /**
     * Send one message.
     *
     * @return bool True when AutoFaya accepted it.
     *
     * @throws RuntimeException When SMS is unavailable or the number is unusable.
     */
    public function send(?string $phone, string $message): bool
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('SMS notifications are not switched on.');
        }

        return $this->dispatch($phone, $message);
    }

    /**
     * Send regardless of the on/off switch, for an administrator verifying the
     * integration before enabling it. Credentials are still required.
     */
    public function sendDirect(?string $phone, string $message): bool
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The AutoFaya API key is not configured.');
        }

        return $this->dispatch($phone, $message);
    }

    private function dispatch(?string $phone, string $message): bool
    {
        $number = $this->normalisePhone($phone);
        if ($number === null) {
            throw new RuntimeException('That is not a valid Tanzanian mobile number.');
        }

        $body = trim($message);
        if ($body === '') {
            throw new RuntimeException('The message is empty.');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.autofaya.base_url'), '/'))
                ->withToken((string) config('services.autofaya.api_key'))
                ->acceptJson()
                ->timeout((int) config('services.autofaya.timeout', 15))
                ->post(self::MESSAGES_PATH, [
                    'sender_name' => $this->senderName(),
                    'phone_number' => $number,
                    'message' => $body,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);
            throw new RuntimeException('Could not reach the SMS service.', previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->json('message')));
        }

        return true;
    }

    private function failureMessage(int $status, mixed $message): string
    {
        $fallback = match ($status) {
            401, 403 => 'The SMS service rejected our credentials.',
            422 => 'The SMS service rejected the message or the number.',
            429 => 'The SMS service is rate limiting us. Please try again shortly.',
            default => 'The message could not be sent.',
        };

        if (in_array($status, [401, 403], true)) {
            // Surfaced separately: a credential problem is an operator issue,
            // not something the person triggering the message can fix.
            report(new RuntimeException("AutoFaya rejected the API key (HTTP {$status})."));

            return $fallback;
        }

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
