<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Content\MediaFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = MediaFile::query()->with('uploader:id,username,email')->latest('id');

        if ($request->filled('folder')) {
            $query->where('folder', (string) $request->input('folder'));
        }

        if ($request->filled('mime_type')) {
            $query->where('mime_type', 'like', $request->input('mime_type').'%');
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('original_name', 'like', "%{$search}%")
                    ->orWhere('alt', 'like', "%{$search}%");
            });
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'], // max 20MB
            'folder' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $uploadedFile = $request->file('file');
        $folder = trim((string) $request->input('folder', 'uploads'), '/');
        if ($folder === '') {
            $folder = 'uploads';
        }

        $path = $uploadedFile->store($folder, 'public');
        $mimeType = $uploadedFile->getMimeType() ?: 'application/octet-stream';
        $sizeBytes = $uploadedFile->getSize();
        $originalName = $uploadedFile->getClientOriginalName();
        $filename = basename($path);

        $metadata = [];
        if (str_starts_with($mimeType, 'image/') && function_exists('getimagesize')) {
            $imageInfo = @getimagesize($uploadedFile->getRealPath());
            if ($imageInfo !== false) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
            }
        }

        $media = MediaFile::create([
            'uploader_id' => $request->user('api')?->id,
            'disk' => 'public',
            'filename' => $filename,
            'original_name' => $originalName,
            'path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'title' => $request->input('title') ?: pathinfo($originalName, PATHINFO_FILENAME),
            'alt' => $request->input('alt'),
            'folder' => $folder,
            'tags' => $request->input('tags', []),
            'metadata' => $metadata,
        ]);

        return $this->success($media, 'Media file uploaded successfully.', 201);
    }

    public function show(MediaFile $media): JsonResponse
    {
        return $this->success($media->loadMissing('uploader:id,username,email'));
    }

    public function update(Request $request, MediaFile $media): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $media->update($validated);

        return $this->success($media->fresh(), 'Media file updated.');
    }

    public function destroy(MediaFile $media): JsonResponse
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        return $this->success(null, 'Media file deleted.');
    }
}
