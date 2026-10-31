<?php

namespace App\Services\AI;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttachmentSecurityScanner
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_SIZE_BYTES = 5242880; // 5 MB

    private const MAX_DIMENSION = 4096;

    private const MALICIOUS_PATTERNS = [
        '<?php',
        '<?=',
        '<script',
        'eval(',
        'base64_decode(',
        'system(',
        'exec(',
        'passthru(',
    ];

    /**
     * @return array{id: string, file_name: string, stored_path: string, mime: string, size: int, width: int, height: int, fingerprint: string}
     */
    public function validateAndStore(UploadedFile $file, int $conversationId): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'images' => ["Attachment extension [{$ext}] is not supported. Allowed: jpg, jpeg, png, webp."],
            ]);
        }

        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'images' => ["Attachment MIME type [{$mime}] is not permitted."],
            ]);
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages([
                'images' => ['Attachment exceeds maximum allowed size of 5 MB.'],
            ]);
        }

        $realPath = $file->getRealPath();
        $imageInfo = @getimagesize($realPath);
        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                'images' => ['The uploaded image file is corrupted or not a valid image.'],
            ]);
        }

        [$width, $height] = $imageInfo;
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw ValidationException::withMessages([
                'images' => ["Image dimensions [{$width}x{$height}] exceed maximum allowed limit of 4096x4096."],
            ]);
        }

        // Security scanning for embedded script payloads
        $handle = fopen($realPath, 'rb');
        if ($handle !== false) {
            $buffer = (string) fread($handle, 4096);
            fclose($handle);

            $lowered = strtolower($buffer);
            foreach (self::MALICIOUS_PATTERNS as $pattern) {
                if (str_contains($lowered, $pattern)) {
                    throw ValidationException::withMessages([
                        'images' => ['Security quarantine rejected attachment due to suspicious embedded payload.'],
                    ]);
                }
            }
        }

        $fingerprint = (string) hash_file('sha256', $realPath);

        $attachmentId = Str::lower(Str::random(16));
        $uniqueFileName = "att_{$conversationId}_{$attachmentId}.{$ext}";
        $storedPath = $file->storeAs('ai/attachments', $uniqueFileName, 'local');

        return [
            'id' => $attachmentId,
            'file_name' => $file->getClientOriginalName(),
            'filename' => $file->getClientOriginalName(),
            'stored_path' => (string) $storedPath,
            'path' => (string) $storedPath,
            'mime' => $mime,
            'mime_type' => $mime,
            'size' => (int) $file->getSize(),
            'width' => (int) $width,
            'height' => (int) $height,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @return array{id: string, file_name: string, filename: string, stored_path: string, path: string, mime: string, mime_type: string, size: int, width: int, height: int, fingerprint: string}
     */
    public function scanAndStore(UploadedFile $file, int $conversationId): array
    {
        return $this->validateAndStore($file, $conversationId);
    }

    /**
     * @param  array<string, mixed>  $attachmentMetadata
     * @return array<string, mixed>|null
     */
    public function toVisionContent(array $attachmentMetadata): ?array
    {
        $path = $attachmentMetadata['stored_path'] ?? $attachmentMetadata['path'] ?? null;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $content = Storage::disk('local')->get($path);
        if ($content === false || $content === '') {
            return null;
        }

        $mime = $attachmentMetadata['mime'] ?? 'image/jpeg';
        $base64 = base64_encode($content);
        $dataUri = "data:{$mime};base64,{$base64}";

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => $dataUri,
            ],
            'mime' => $mime,
            'base64' => $base64,
        ];
    }

    public function delete(?string $storedPath): void
    {
        if ($storedPath && Storage::disk('local')->exists($storedPath)) {
            Storage::disk('local')->delete($storedPath);
        }
    }
}
