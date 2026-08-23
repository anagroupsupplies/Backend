<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mobile money collections through MALIPOPAY.
 *
 * @see https://developers.malipopay.co.tz/api-reference/collection-unified
 */
class MalipoPayService
{
    private const COLLECTION_PATH = '/api/v2/payment/collection';

    public function isConfigured(): bool
    {
        return (string) config('services.malipopay.api_token') !== '';
    }

    /**
     * Normalise a Tanzanian mobile number to the 255XXXXXXXXX form.
     */
    public function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '255')) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '255'.substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            return '255'.$digits;
        }

        return $digits;
    }

    public function isValidPhone(string $phone): bool
    {
        return (bool) preg_match('/^255[67]\d{8}$/', $this->normalisePhone($phone));
    }

    /**
     * Send a mobile money push to the customer for this order.
     *
     * Returns immediately with status PROCESSING; the final outcome arrives
     * on the webhook.
     *
     * @return array{reference: string, status: string, transactionId: ?string, channel: ?string, link: ?string}
     */
    public function collect(Order $order, string $phone): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Mobile money payments are not configured.');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.malipopay.base_url'), '/'))
                ->withHeaders(['apiToken' => (string) config('services.malipopay.api_token')])
                ->acceptJson()
                ->timeout((int) config('services.malipopay.timeout', 30))
                ->post(self::COLLECTION_PATH, [
                    'reference' => $order->number,
                    'description' => 'Payment for order '.$order->number,
                    'amount' => (int) round((float) $order->total),
                    'service' => 'mobile',
                    'account' => $this->normalisePhone($phone),
                    'amountType' => 'FULL',
                    'push' => true,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);
            throw new RuntimeException('Could not reach the mobile money service. Please try again.', previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->failureMessage($response->status(), $response->json('message')));
        }

        $data = $response->json('data') ?? [];
        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            throw new RuntimeException('The mobile money service returned an unexpected response.');
        }

        return [
            'reference' => $reference,
            'status' => (string) ($data['status'] ?? 'PROCESSING'),
            'transactionId' => isset($data['id']) ? (string) $data['id'] : null,
            'channel' => isset($data['customer']['mno']) ? (string) $data['customer']['mno'] : null,
            'link' => isset($data['link']) ? (string) $data['link'] : null,
        ];
    }

    /**
     * Verify the `X-Malipopay-Signature: sha256=<hex>` header against the raw
     * request body using the per-webhook signing secret.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('services.malipopay.webhook_secret');

        if ($secret === '' || ! is_string($signatureHeader) || $signatureHeader === '') {
            return false;
        }

        $provided = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $provided);
    }

    private function failureMessage(int $status, mixed $message): string
    {
        $fallback = match ($status) {
            400 => 'The payment was rejected. If the shop is still in test mode the number must be an approved test recipient.',
            402 => 'The daily or monthly transaction limit has been reached. Please try again later.',
            403 => 'Mobile money is temporarily unavailable. Please choose pay on delivery.',
            default => 'The mobile money request could not be completed. Please try again.',
        };

        if ($status === 403) {
            report(new RuntimeException('MALIPOPAY rejected the API token (HTTP 403).'));

            return $fallback;
        }

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
