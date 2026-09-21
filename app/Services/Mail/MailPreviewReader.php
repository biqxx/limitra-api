<?php

namespace App\Services\Mail;

class MailPreviewReader
{
    private const MAX_BYTES = 1_048_576;

    private const MAX_ITEMS = 10;

    /** @return list<array{sent_at: string|null, message: string}> */
    public function recent(): array
    {
        $path = config('logging.channels.mail_preview.path');

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $size = filesize($path);

        if ($size === false || $size === 0) {
            return [];
        }

        $offset = max(0, $size - self::MAX_BYTES);
        $contents = file_get_contents($path, false, null, $offset, self::MAX_BYTES);

        if ($contents === false) {
            return [];
        }

        if ($offset > 0) {
            $firstNewline = strpos($contents, "\n");
            $contents = $firstNewline === false ? '' : substr($contents, $firstNewline + 1);
        }

        $items = [];

        foreach (array_reverse(explode("\n", trim($contents))) as $line) {
            $entry = json_decode($line, true);

            if (! is_array($entry) || ! is_string($entry['message'] ?? null)
                || ! str_contains($entry['message'], 'To:')
                || ! str_contains($entry['message'], 'Subject:')) {
                continue;
            }

            $items[] = [
                'sent_at' => $entry['datetime'] ?? null,
                'message' => $entry['message'],
            ];

            if (count($items) === self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }
}
