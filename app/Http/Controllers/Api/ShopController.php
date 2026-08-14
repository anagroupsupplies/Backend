<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShopController extends Controller
{
    public function show(Shop $shop): JsonResponse
    {
        abort_unless($shop->is_active, 404);

        return response()->json(['data' => $this->shopData($shop)]);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->shopData($this->ownedShop($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'logo' => ['nullable', 'string'],
            'banner' => ['nullable', 'string'],
            'primaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accentColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $shop = $this->ownedShop($request);
        $settings = $shop->settings ?? [];
        foreach (['primaryColor', 'accentColor'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = $data[$key];
                unset($data[$key]);
            }
        }
        $shop->update([...$data, 'settings' => $settings]);

        return response()->json(['data' => $this->shopData($shop->fresh())]);
    }

    private function ownedShop(Request $request): Shop
    {
        $user = $request->user();

        return Shop::firstOrCreate(
            ['seller_id' => $user->id],
            ['name' => $user->name."'s Shop", 'slug' => Str::slug($user->name.' shop').'-'.$user->id],
        );
    }

    /** @return array<string, mixed> */
    private function shopData(Shop $shop): array
    {
        return [
            'id' => (string) $shop->id,
            'name' => $shop->name,
            'slug' => $shop->slug,
            'description' => $shop->description,
            'logo' => $shop->logo,
            'banner' => $shop->banner,
            'primaryColor' => $shop->settings['primaryColor'] ?? '#3157d5',
            'accentColor' => $shop->settings['accentColor'] ?? '#111a2e',
            'isActive' => $shop->is_active,
        ];
    }
}
