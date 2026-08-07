<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => Setting::where('key', 'general')->value('value') ?? ['whatsappNumber' => '255683568254']]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['whatsappNumber' => ['required', 'string', 'max:30'], 'businessName' => ['nullable', 'string', 'max:255'], 'businessEmail' => ['nullable', 'email'], 'supportPhone' => ['nullable', 'string', 'max:30']]);
        Setting::updateOrCreate(['key' => 'general'], ['value' => $data]);

        return response()->json(['data' => $data]);
    }
}
