<?php

namespace App\Social\Channels;

use App\Social\Contracts\SocialChannel;
use App\Social\Data\InboundMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class XChannel implements SocialChannel
{
    public function handles(string $platform): bool
    {
        return $platform === 'x';
    }

    /**
     * Verify X's CRC challenge (GET request).
     * X expects: {"response_token": "sha256=base64(HMAC-SHA256(consumer_secret, crc_token))"}
     */
    public function verifyCrc(Request $request): ?string
    {
        $crcToken = $request->query('crc_token');

        if (! $crcToken) {
            return null;
        }

        $hash = base64_encode(
            hash_hmac('sha256', $crcToken, config('x.consumer_secret'), true)
        );

        return 'sha256='.$hash;
    }

    /**
     * Validate the incoming X webhook signature.
     */
    public function validateSignature(Request $request): bool
    {
        $signature = $request->header('X-Twitter-Webhooks-Signature');

        if (! $signature) {
            return false;
        }

        $expected = 'sha256='.base64_encode(
            hash_hmac('sha256', $request->getContent(), config('x.consumer_secret'), true)
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Parse a raw X Account Activity API payload into a standardised DTO.
     * Returns null if the payload contains no actionable DM (e.g. self-sent, non-text).
     */
    public function parseInboundPayload(array $payload): ?InboundMessage
    {
        $events = $payload['direct_message_events'] ?? [];
        $users = $payload['users'] ?? [];
        $botId = config('x.bot_user_id');

        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'message_create') {
                continue;
            }

            $mc = $event['message_create'];
            $senderId = $mc['sender_id'] ?? null;

            // Ignore messages the bot sent itself.
            if (! $senderId || $senderId === $botId) {
                continue;
            }

            $text = $mc['message_data']['text'] ?? null;

            if (! $text) {
                continue;
            }

            $providerMessageId = (string) ($event['id'] ?? $event['id_str'] ?? '');

            if ($providerMessageId === '') {
                continue;
            }

            $senderProfile = $users[$senderId] ?? [];

            return new InboundMessage(
                platform: 'x',
                providerMessageId: $providerMessageId,
                platformSenderId: $senderId,
                platformRecipientId: $botId ?? ($mc['target']['recipient_id'] ?? ''),
                message: $text,
                username: $senderProfile['screen_name'] ?? $senderProfile['name'] ?? null,
                timestamp: (int) (($event['created_timestamp'] ?? time() * 1000) / 1000),
            );
        }

        return null;
    }

    /**
     * Send a DM via X API v2 (requires user-level OAuth 1.0a).
     */
    public function sendTextMessage(string $platform, string $recipientId, string $message): void
    {
        $url = config('x.base_url').'/2/dm_conversations/with/'.$recipientId.'/messages';
        $body = json_encode(['text' => $message]);
        $authHeader = $this->buildOAuth1Header('POST', $url, $body);

        Http::withHeaders([
            'Authorization' => $authHeader,
            'Content-Type' => 'application/json',
        ])
            ->timeout(10)
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();
    }

    // ── OAuth 1.0a signing ────────────────────────────────────────────────────

    /**
     * Build the Authorization header for an OAuth 1.0a signed request.
     * X's DM endpoint requires user-context auth; bearer token is insufficient.
     */
    private function buildOAuth1Header(string $method, string $url, string $body): string
    {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string) time();

        $oauthParams = [
            'oauth_consumer_key' => config('x.consumer_key'),
            'oauth_nonce' => $nonce,
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp' => $timestamp,
            'oauth_token' => config('x.access_token'),
            'oauth_version' => '1.0',
        ];

        $signature = $this->buildSignature($method, $url, $oauthParams);

        $oauthParams['oauth_signature'] = $signature;

        $parts = array_map(
            fn (string $k, string $v) => rawurlencode($k).'="'.rawurlencode($v).'"',
            array_keys($oauthParams),
            array_values($oauthParams),
        );

        return 'OAuth '.implode(', ', $parts);
    }

    private function buildSignature(string $method, string $url, array $oauthParams): string
    {
        $params = $oauthParams;

        ksort($params);

        $paramString = implode('&', array_map(
            fn (string $k, string $v) => rawurlencode($k).'='.rawurlencode($v),
            array_keys($params),
            array_values($params),
        ));

        $baseString = strtoupper($method)
            .'&'.rawurlencode($url)
            .'&'.rawurlencode($paramString);

        $signingKey = rawurlencode(config('x.consumer_secret'))
            .'&'.rawurlencode(config('x.access_secret'));

        return base64_encode(hash_hmac('sha256', $baseString, $signingKey, true));
    }
}
