<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\AutoFayaSmsService;
use App\Services\MalipoPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private readonly MalipoPayService $malipoPay,
        private readonly AutoFayaSmsService $sms,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->data()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'whatsappNumber' => ['required', 'string', 'max:30'],
            'businessName' => ['nullable', 'string', 'max:255'],
            'businessEmail' => ['nullable', 'email'],
            'supportPhone' => ['nullable', 'string', 'max:30'],
            'mobileMoneyEnabled' => ['sometimes', 'boolean'],
            'escrowEnabled' => ['sometimes', 'boolean'],
            'escrowHoldingDays' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'commissionRate' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'smsEnabled' => ['sometimes', 'boolean'],
            // Networks reject long or punctuated sender IDs.
            'smsSenderName' => ['sometimes', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ]+$/'],
        ]);
        $before = Setting::general();
        Setting::putGeneral($data);

        if ($changes = $this->audit->diff($before, Setting::general())) {
            $this->audit->record('settings.updated', null, $changes, 'Updated global shop settings');
        }

        return response()->json(['data' => $this->data()]);
    }

    /**
     * Turn mobile money collections on or off for the whole platform. Kept as
     * its own endpoint so the switch does not require resubmitting every other
     * setting.
     */
    public function updateMobileMoney(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $was = (bool) (Setting::general()['mobileMoneyEnabled'] ?? true);
        Setting::putGeneral(['mobileMoneyEnabled' => $data['enabled']]);

        if ($was !== $data['enabled']) {
            $this->audit->record('settings.mobile_money_toggled', null, ['mobileMoneyEnabled' => ['from' => $was, 'to' => $data['enabled']]], 'Mobile money payments switched '.($data['enabled'] ? 'ON' : 'OFF'));
        }

        return response()->json(['data' => $this->data()]);
    }

    /**
     * Turn outgoing SMS on or off without resubmitting every other setting.
     */
    public function updateSms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'senderName' => ['sometimes', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ]+$/'],
        ]);

        $before = Setting::general();
        Setting::putGeneral(array_filter([
            'smsEnabled' => $data['enabled'] ?? null,
            'smsSenderName' => $data['senderName'] ?? null,
        ], fn ($value) => $value !== null));

        if ($changes = $this->audit->diff(
            ['smsEnabled' => $before['smsEnabled'] ?? false, 'smsSenderName' => $before['smsSenderName'] ?? null],
            ['smsEnabled' => Setting::general()['smsEnabled'], 'smsSenderName' => Setting::general()['smsSenderName']],
        )) {
            $this->audit->record('settings.sms_updated', null, $changes, 'Updated the SMS notification settings');
        }

        return response()->json(['data' => $this->data()]);
    }

    /**
     * Send one real message so an administrator can prove the integration
     * works before switching it on for customers.
     */
    public function testSms(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        abort_unless($this->sms->isConfigured(), 422, 'The AutoFaya API key is not configured on the server.');
        abort_if($this->sms->normalisePhone($data['phone']) === null, 422, 'Enter a valid Tanzanian mobile number, for example 0712345678.');

        try {
            // Bypasses the on/off switch on purpose: the point is to verify the
            // integration before enabling it.
            $this->sms->sendDirect($data['phone'], 'Test message from '.$this->sms->senderName().'. Your SMS notifications are working.');
        } catch (\RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        $this->audit->record('settings.sms_tested', null, ['phone' => $data['phone']], 'Sent a test SMS');

        return response()->json(['message' => 'Test message sent.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $settings = Setting::general();
        $enabled = (bool) ($settings['mobileMoneyEnabled'] ?? true);

        return [
            ...$settings,
            'mobileMoneyEnabled' => $enabled,
            // Credentials are configured on the server, so the switch alone is
            // not enough to promise the storefront that payments will work.
            'mobileMoneyConfigured' => $this->malipoPay->isConfigured(),
            'mobileMoneyAvailable' => $enabled && $this->malipoPay->isConfigured(),
            'smsEnabled' => $this->sms->isEnabled(),
            'smsConfigured' => $this->sms->isConfigured(),
            'smsAvailable' => $this->sms->isAvailable(),
            'smsSenderName' => $this->sms->senderName(),
        ];
    }
}
