<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Media::latest()->paginate(50)->through(fn (Media $media): array => $this->data($media))]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);
        $file = $request->file('image');
        $path = $file->storeAs(
            'products/'.now()->format('Y/m'),
            Str::uuid().'.'.$file->extension(),
            'public',
        );

        abort_if($path === false, 500, 'The image could not be stored.');
        $media = Media::create([
            'uploaded_by' => $request->user()->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return response()->json(['data' => $this->data($media)], 201);
    }

    public function destroy(Media $medium): JsonResponse
    {
        abort_unless(request()->user()->isMaster() || $medium->uploaded_by === request()->user()->id, 403, 'You can only delete media that you uploaded.');
        Storage::disk($medium->disk)->delete($medium->path);
        $medium->delete();

        return response()->json([], 204);
    }

    /** @return array<string, mixed> */
    private function data(Media $media): array
    {
        return [
            'id' => (string) $media->id,
            'url' => url(Storage::disk($media->disk)->url($media->path)),
            'path' => $media->path,
            'name' => $media->original_name,
            'mimeType' => $media->mime_type,
            'size' => $media->size,
            'createdAt' => $media->created_at,
        ];
    }
}
