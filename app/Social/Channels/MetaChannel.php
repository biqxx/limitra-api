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
     * Parse a raw Meta webhook payload into a standardized DTO.
     * Returns null if the payload contains no actionable message.
     */
    public function parseInboundPayload(array $payload): ?InboundMessage
    {
        $entry = $payload['entry'][0] ?? null;
        $object = $payload['object'] ?? null;

        if (! $entry || ! $object) {
            return null;
        }

        return match ($object) {
            'whatsapp_business_account' => $this->parseWhatsApp($entry),
            'instagram' => $this->parseInstagram($entry),
            'page' => $this->parseFacebook($entry),
            default => null,
        };
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

    private function parseWhatsApp(array $entry): ?InboundMessage
    {
        $change = $entry['changes'][0] ?? null;
        $value = $change['value'] ?? null;
        $msgData = $value['messages'][0] ?? null;
        $contact = $value['contacts'][0] ?? null;

        if (! $msgData || ($msgData['type'] ?? '') !== 'text') {
            return null;
        }

        return new InboundMessage(
            platform: 'whatsapp',
            platformSenderId: $msgData['from'],
            platformRecipientId: $value['metadata']['phone_number_id'] ?? '',
            message: $msgData['text']['body'] ?? '',
            username: $contact['profile']['name'] ?? null,
            timestamp: (int) ($msgData['timestamp'] ?? time()),
        );
    }

    private function parseInstagram(array $entry): ?InboundMessage
    {
        $messaging = $entry['messaging'][0] ?? null;

        if (! $messaging || ! isset($messaging['message']['text'])) {
            return null;
        }

        return new InboundMessage(
            platform: 'instagram',
            platformSenderId: $messaging['sender']['id'],
            platformRecipientId: $messaging['recipient']['id'],
            message: $messaging['message']['text'],
            username: null,
            timestamp: (int) ($messaging['timestamp'] ?? time()),
        );
    }

    private function parseFacebook(array $entry): ?InboundMessage
    {
        $messaging = $entry['messaging'][0] ?? null;

        if (! $messaging || ! isset($messaging['message']['text'])) {
            return null;
        }

        return new InboundMessage(
            platform: 'facebook',
            platformSenderId: $messaging['sender']['id'],
            platformRecipientId: $messaging['recipient']['id'],
            message: $messaging['message']['text'],
            username: null,
            timestamp: (int) ($messaging['timestamp'] ?? time()),
        );
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
