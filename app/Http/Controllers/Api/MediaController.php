<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\AuditLogger;
use getID3;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function index(): JsonResponse
    {
        $query = Media::query()->latest();

        if (! request()->user()->isAdmin()) {
            $query->where('uploaded_by', request()->user()->id);
        }

        return response()->json(['data' => $query->paginate(50)->through(fn (Media $media): array => $this->data($media))]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required_without:video', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'video' => ['required_without:image', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:51200'],
        ]);

        abort_if($request->hasFile('image') && $request->hasFile('video'), 422, 'Upload either an image or a video, not both.');
        $type = $request->hasFile('video') ? 'video' : 'image';
        $file = $request->file($type);

        if ($type === 'video') {
            try {
                $getID3 = new getID3;
                $info = $getID3->analyze($file->getRealPath());
                $duration = (float) ($info['playtime_seconds'] ?? 0);
                abort_if($duration > 50.9, 422, 'Video duration must not exceed 50 seconds.');
            } catch (\Throwable $e) {
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    throw $e;
                }
            }
        }

        $path = $file->storeAs(
            'products/'.$type.'s/'.now()->format('Y/m'),
            Str::uuid().'.'.$file->extension(),
            'public',
        );

        abort_if($path === false, 500, 'The file could not be stored.');
        $media = Media::create([
            'uploaded_by' => $request->user()->id,
            'type' => $type,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => ['durationLimitSeconds' => $type === 'video' ? 50 : null],
        ]);

        return response()->json(['data' => $this->data($media)], 201);
    }

    public function destroy(Media $medium): JsonResponse
    {
        abort_unless(request()->user()->isAdmin() || $medium->uploaded_by === request()->user()->id, 403, 'You can only delete media that you uploaded.');
        app(AuditLogger::class)->record('media.deleted', $medium, ['path' => $medium->path, 'uploadedBy' => $medium->uploaded_by], "Deleted the {$medium->type} {$medium->original_name}");
        Storage::disk($medium->disk)->delete($medium->path);
        $medium->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function data(Media $media): array
    {
        return [
            'id' => (string) $media->id,
            'url' => url(Storage::disk($media->disk)->url($media->path)),
            'type' => $media->type,
            'path' => $media->path,
            'name' => $media->original_name,
            'mimeType' => $media->mime_type,
            'size' => $media->size,
            'metadata' => $media->metadata,
            'createdAt' => $media->created_at,
        ];
    }
}
