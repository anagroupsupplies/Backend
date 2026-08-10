<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Banner::where('is_active', true)->orderBy('order')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'image' => ['required', 'string'],
            'link' => ['nullable', 'string', 'max:500'],
            'order' => ['nullable', 'integer', 'min:0'],
            'buttonColor' => ['nullable', 'string', 'max:30'],
        ]);
        $buttonColor = $data['buttonColor'] ?? null;
        unset($data['buttonColor']);
        $banner = Banner::create([...$data, 'button_color' => $buttonColor]);

        return response()->json(['data' => $banner], 201);
    }

    public function update(Request $request, Banner $banner): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'image' => ['sometimes', 'string'],
            'link' => ['nullable', 'string', 'max:500'],
            'order' => ['nullable', 'integer', 'min:0'],
            'isActive' => ['nullable', 'boolean'],
            'buttonColor' => ['nullable', 'string', 'max:30'],
        ]);
        if (array_key_exists('isActive', $data)) {
            $data['is_active'] = $data['isActive'];
            unset($data['isActive']);
        }
        if (array_key_exists('buttonColor', $data)) {
            $data['button_color'] = $data['buttonColor'];
            unset($data['buttonColor']);
        }
        $banner->update($data);

        return response()->json(['data' => $banner]);
    }

    public function destroy(Banner $banner): JsonResponse
    {
        $banner->delete();

        return response()->json([], 204);
    }
}
