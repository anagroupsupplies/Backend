<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\MalipoPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly MalipoPayService $malipoPay) {}

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
        ]);
        Setting::putGeneral($data);

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
        Setting::putGeneral(['mobileMoneyEnabled' => $data['enabled']]);

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
