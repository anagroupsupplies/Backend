<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeepSeekService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiChatController extends Controller
{
    public function __invoke(Request $request, DeepSeekService $deepSeek): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:10'],
            'history.*.type' => ['required', 'in:user,bot'],
            'history.*.message' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => ['response' => $deepSeek->chat($data['message'], $data['history'] ?? [])],
        ]);
    }
}
