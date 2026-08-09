<?php

use App\Http\Middleware\AuthenticateFirebase;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->withoutMiddleware(AuthenticateFirebase::class);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
});

test('an administrator can upload an image to laravel managed storage', function () {
    $response = $this->postJson('/api/v1/admin/media', [
        'image' => UploadedFile::fake()->create('jersey.jpg', 500, 'image/jpeg'),
    ])->assertCreated()
        ->assertJsonPath('data.name', 'jersey.jpg')
        ->assertJsonPath('data.mimeType', 'image/jpeg');

    $media = Media::firstOrFail();
    Storage::disk('public')->assertExists($media->path);
    expect($response->json('data.url'))->toContain('/storage/products/');
});

test('deleting media removes its file and database record', function () {
    Storage::disk('public')->put('products/test.jpg', 'image');
    $media = Media::create([
        'uploaded_by' => auth()->id(), 'disk' => 'public', 'path' => 'products/test.jpg',
        'original_name' => 'test.jpg', 'mime_type' => 'image/jpeg', 'size' => 5,
    ]);

    $this->deleteJson("/api/v1/admin/media/{$media->id}")->assertNoContent();

    Storage::disk('public')->assertMissing('products/test.jpg');
    expect(Media::count())->toBe(0);
});

test('non image files are rejected', function () {
    $this->postJson('/api/v1/admin/media', [
        'image' => UploadedFile::fake()->create('malware.php', 10, 'text/x-php'),
    ])->assertUnprocessable();

    expect(Media::count())->toBe(0);
});
