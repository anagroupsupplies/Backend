<?php

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('deepseek receives the customer conversation and current mysql inventory', function () {
    config([
        'services.deepseek.api_key' => 'test-key',
        'services.deepseek.model' => 'deepseek-v4-flash',
    ]);
    Product::create(['name' => 'Blue Jersey', 'slug' => 'blue-jersey', 'price' => 45000, 'stock' => 3]);
    Http::fake([
        'api.deepseek.com/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'The Blue Jersey costs TZS 45,000.']]],
        ]),
    ]);

    $this->postJson('/api/v1/ai/chat', [
        'message' => 'What jersey is available?',
        'history' => [['type' => 'bot', 'message' => 'How can I help?']],
    ])->assertOk()->assertJsonPath('data.response', 'The Blue Jersey costs TZS 45,000.');

    Http::assertSent(function (Request $request): bool {
        $messages = $request->data()['messages'];

        return $request->hasHeader('Authorization', 'Bearer test-key')
            && str_contains($messages[0]['content'], 'Blue Jersey')
            && $messages[1] === ['role' => 'assistant', 'content' => 'How can I help?']
            && $messages[2] === ['role' => 'user', 'content' => 'What jersey is available?'];
    });
});

test('ai chat validates messages before contacting deepseek', function () {
    Http::fake();
    $this->postJson('/api/v1/ai/chat', ['message' => ''])->assertUnprocessable();
    Http::assertNothingSent();
});
