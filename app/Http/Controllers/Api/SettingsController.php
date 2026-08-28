<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\MalipoPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private readonly MalipoPayService $malipoPay,
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
        ];
    }
}
