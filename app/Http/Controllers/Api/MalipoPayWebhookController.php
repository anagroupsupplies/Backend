<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MalipoPayService;
use App\Services\OrderNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receives MALIPOPAY collection callbacks.
 *
 * @see https://developers.malipopay.co.tz/integration/webhooks
 */
class MalipoPayWebhookController extends Controller
{
    /** Collection statuses that mean the money arrived in full. */
    private const PAID_STATUSES = ['SUCCESSFUL', 'PAID'];

    private const FAILED_STATUSES = ['FAILED', 'REJECTED', 'CANCELLED'];

    public function __construct(
        private readonly MalipoPayService $malipoPay,
        private readonly OrderNotifier $notifier,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->malipoPay->verifyWebhookSignature($request->getContent(), $request->header('X-Malipopay-Signature'))) {
            logger()->warning('Rejected a MALIPOPAY webhook with an invalid signature.', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        $event = (string) ($payload['event'] ?? '');
        $status = strtoupper((string) ($payload['status'] ?? ''));
        $order = $this->resolveOrder($payload);

        if (! $order) {
            // Acknowledge so MALIPOPAY stops retrying a callback we cannot match.
            logger()->warning('Received a MALIPOPAY webhook for an unknown order.', ['reference' => $payload['reference'] ?? null, 'customerReference' => $payload['customerReference'] ?? null]);

            return response()->json(['message' => 'Order not found.']);
        }

        if ($order->payment_method !== Order::PAYMENT_MOBILE_MONEY) {
            return response()->json(['message' => 'Ignored.']);
        }

        $this->apply($order, $event, $status, $payload);

        return response()->json(['message' => 'Received.']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function apply(Order $order, string $event, string $status, array $payload): void
    {
        $amount = (float) ($payload['amount'] ?? 0);
        $transactionId = isset($payload['transactionId']) ? (string) $payload['transactionId'] : null;
        $channel = isset($payload['customer']['mno']) ? (string) $payload['customer']['mno'] : null;

        if ($event === 'payment.confirmed' && in_array($status, self::PAID_STATUSES, true)) {
            // The webhook is retried up to five times, so only act on the first
            // delivery that actually moves the order into the paid state.
            $alreadyPaid = $order->isPaid();
            DB::transaction(fn () => $order->markPaid($amount, $transactionId, $channel));

            if (! $alreadyPaid) {
                $this->notifier->paymentConfirmed($order->refresh());
            }

            return;
        }

        if ($status === 'PARTIAL') {
            $order->forceFill(['payment_status' => Order::PAY_STATUS_PARTIAL, 'paid_amount' => $amount, 'payment_transaction_id' => $transactionId ?? $order->payment_transaction_id])->save();

            return;
        }

        if ($event === 'payment.failed' || in_array($status, self::FAILED_STATUSES, true)) {
            $order->forceFill([
                'payment_status' => Order::PAY_STATUS_FAILED,
                'payment_failure_reason' => (string) ($payload['failureReason'] ?? 'The payment was not completed.'),
                'payment_transaction_id' => $transactionId ?? $order->payment_transaction_id,
            ])->save();

            return;
        }

        if ($event === 'payment.refunded') {
            $order->forceFill(['payment_status' => Order::PAY_STATUS_FAILED, 'paid_amount' => 0, 'paid_at' => null, 'payment_failure_reason' => 'The payment was refunded.'])->save();
        }
    }

    /**
     * Match on our own order number first, then on the MALIPOPAY reference.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrder(array $payload): ?Order
    {
        $customerReference = $payload['customerReference'] ?? null;
        $reference = $payload['reference'] ?? null;

        if (is_string($customerReference) && $customerReference !== '') {
            $order = Order::where('number', $customerReference)->first();
            if ($order) {
                return $order;
            }
        }

        if (is_string($reference) && $reference !== '') {
            return Order::where('payment_reference', $reference)->first();
        }

        return null;
    }
}
