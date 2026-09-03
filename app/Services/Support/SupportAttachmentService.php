<?php

namespace App\Services\Support;

use App\Models\Support\SupportTicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SupportAttachmentService
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string>
     */
    public function store(SupportTicketMessage $message, array $files): array
    {
        $storedPaths = [];

        try {
            foreach ($files as $file) {
                $extension = $file->guessExtension() ?: 'bin';
                $path = $file->storeAs(
                    'support/tickets/'.$message->support_ticket_id,
                    Str::ulid().'.'.$extension,
                    'local',
                );

                if (! $path) {
                    throw new RuntimeException('The support attachment could not be stored.');
                }

                $storedPaths[] = $path;
                $message->attachments()->create([
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }

        return $storedPaths;
    }
}
