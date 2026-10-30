<?php

namespace App\Social\Channels;

use App\Social\Contracts\SocialChannel;
use App\Social\Data\InboundMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaChannel implements SocialChannel
{
    public function handles(string $platform): bool
    {
        return in_array($platform, ['whatsapp', 'instagram', 'facebook'], true);
    }

    /**
     * Verify the Meta webhook challenge (GET request from Meta).
     */
    public function verifyWebhook(Request $request): bool
    {
        return $request->query('hub_mode') === 'subscribe'
            && $request->query('hub_verify_token') === config('meta.verify_token');
    }

    /**
     * Validate the request signature using the app secret (POST requests).
     */
    public function validateSignature(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (! $signature) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), config('meta.app_secret'));

        return hash_equals($expected, $signature);
    }

    /**
     * Parse a raw Meta webhook payload into standardized InboundMessage DTOs.
     *
     * @return array<int, InboundMessage>
     */
    public function parseInboundPayloads(array $payload): array
    {
        $object = $payload['object'] ?? null;
        $entries = $payload['entry'] ?? [];

        if (! is_string($object) || ! is_array($entries)) {
            return [];
        }

        $messages = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $parsed = match ($object) {
                'whatsapp_business_account' => $this->parseWhatsAppMessages($entry),
                'instagram' => $this->parseInstagramMessages($entry),
                'page' => $this->parseFacebookMessages($entry),
                default => [],
            };

            foreach ($parsed as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Parse a raw Meta webhook payload into a standardized DTO.
     * Returns null if the payload contains no actionable message.
     */
    public function parseInboundPayload(array $payload): ?InboundMessage
    {
        return $this->parseInboundPayloads($payload)[0] ?? null;
    }

    /**
     * Send a plain-text message via the appropriate platform.
     */
    public function sendTextMessage(string $platform, string $recipientId, string $message): void
    {
        match ($platform) {
            'whatsapp' => $this->sendWhatsApp($recipientId, $message),
            'instagram' => $this->sendInstagram($recipientId, $message),
            'facebook' => $this->sendFacebook($recipientId, $message),
            default => Log::warning("MetaChannel: unknown platform '{$platform}'"),
        };
    }

    // ── Platform parsers ──────────────────────────────────────────────────────

    /** @return array<int, InboundMessage> */
    private function parseWhatsAppMessages(array $entry): array
    {
        $changes = $entry['changes'] ?? [];
        if (! is_array($changes)) {
            return [];
        }

        $messages = [];

        foreach ($changes as $change) {
            $value = $change['value'] ?? null;
            if (! is_array($value)) {
                continue;
            }

            $rawMessages = $value['messages'] ?? [];
            if (! is_array($rawMessages)) {
                continue;
            }

            $contact = $value['contacts'][0] ?? null;
            $username = is_array($contact) ? ($contact['profile']['name'] ?? null) : null;
            $recipientId = (string) ($value['metadata']['phone_number_id'] ?? '');

            foreach ($rawMessages as $msgData) {
                if (! is_array($msgData) || ($msgData['type'] ?? '') !== 'text') {
                    continue;
                }

                $providerMessageId = (string) ($msgData['id'] ?? '');
                if ($providerMessageId === '') {
                    continue;
                }

                $messages[] = new InboundMessage(
                    platform: 'whatsapp',
                    providerMessageId: $providerMessageId,
                    platformSenderId: (string) ($msgData['from'] ?? ''),
                    platformRecipientId: $recipientId,
                    message: (string) ($msgData['text']['body'] ?? ''),
                    username: $username,
                    timestamp: (int) ($msgData['timestamp'] ?? time()),
                );
            }
        }

        return $messages;
    }

    /** @return array<int, InboundMessage> */
    private function parseInstagramMessages(array $entry): array
    {
        $messagingList = $entry['messaging'] ?? [];
        if (! is_array($messagingList)) {
            return [];
        }

        $messages = [];

        foreach ($messagingList as $messaging) {
            if (! is_array($messaging) || ! isset($messaging['message']['text'])) {
                continue;
            }

            $providerMessageId = (string) ($messaging['message']['mid'] ?? '');
            if ($providerMessageId === '') {
                continue;
            }

            $messages[] = new InboundMessage(
                platform: 'instagram',
                providerMessageId: $providerMessageId,
                platformSenderId: (string) ($messaging['sender']['id'] ?? ''),
                platformRecipientId: (string) ($messaging['recipient']['id'] ?? ''),
                message: (string) $messaging['message']['text'],
                username: null,
                timestamp: (int) ($messaging['timestamp'] ?? time()),
            );
        }

        return $messages;
    }

    /** @return array<int, InboundMessage> */
    private function parseFacebookMessages(array $entry): array
    {
        $messagingList = $entry['messaging'] ?? [];
        if (! is_array($messagingList)) {
            return [];
        }

        $messages = [];

        foreach ($messagingList as $messaging) {
            if (! is_array($messaging) || ! isset($messaging['message']['text'])) {
                continue;
            }

            $providerMessageId = (string) ($messaging['message']['mid'] ?? '');
            if ($providerMessageId === '') {
                continue;
            }

            $messages[] = new InboundMessage(
                platform: 'facebook',
                providerMessageId: $providerMessageId,
                platformSenderId: (string) ($messaging['sender']['id'] ?? ''),
                platformRecipientId: (string) ($messaging['recipient']['id'] ?? ''),
                message: (string) $messaging['message']['text'],
                username: null,
                timestamp: (int) ($messaging['timestamp'] ?? time()),
            );
        }

        return $messages;
    }

    // ── Platform senders ──────────────────────────────────────────────────────

    private function sendWhatsApp(string $recipientId, string $message): void
    {
        $numberId = config('meta.whatsapp.number_id');
        $token = config('meta.whatsapp.token');

        Http::withToken($token)
            ->timeout(10)
            ->post(config('meta.whatsapp.base_url')."/{$numberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $recipientId,
                'type' => 'text',
                'text' => ['body' => $message],
            ])
            ->throw();
    }

    private function sendInstagram(string $recipientId, string $message): void
    {
        $pageId = config('meta.instagram.page_id');
        $token = config('meta.instagram.token');

        Http::withToken($token)
            ->timeout(10)
            ->post(config('meta.instagram.base_url')."/{$pageId}/messages", [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
            ])
            ->throw();
    }

    private function sendFacebook(string $recipientId, string $message): void
    {
        $pageId = config('meta.facebook.page_id');
        $token = config('meta.facebook.token');

        Http::withToken($token)
            ->timeout(10)
            ->post(config('meta.facebook.base_url')."/{$pageId}/messages", [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
            ])
            ->throw();
    }
}
