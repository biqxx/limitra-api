<?php

namespace App\Services\AI;

class TranscriptRedactionService
{
    private const EMAIL_REGEX = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

    private const CARD_REGEX = '/\b(?:\d[ -]*?){13,16}\b/';

    private const PHONE_REGEX = '/\b(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/';

    public function redact(string $text): string
    {
        $redacted = preg_replace(self::CARD_REGEX, '[CARD_REDACTED]', $text);
        $redacted = preg_replace(self::EMAIL_REGEX, '[EMAIL_REDACTED]', (string) $redacted);
        $redacted = preg_replace(self::PHONE_REGEX, '[PHONE_REDACTED]', (string) $redacted);

        return (string) $redacted;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public function redactTranscript(array $messages): array
    {
        return array_map(function (array $msg): array {
            if (isset($msg['content']) && is_string($msg['content'])) {
                $msg['content'] = $this->redact($msg['content']);
            }

            return $msg;
        }, $messages);
    }
}
