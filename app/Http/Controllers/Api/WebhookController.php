<?php

namespace App\Http\Controllers\Api;

use App\Social\Channels\MetaChannel;
use App\Social\Data\InboundMessage;
use App\Social\InboundMessageReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends BaseController
{
    public function __construct(
        private readonly MetaChannel $meta,
        private readonly InboundMessageReceiver $receiver,
    ) {}

    /**
     * GET /webhook/meta — Meta webhook verification challenge.
     */
    public function verify(Request $request): Response
    {
        if (! $this->meta->verifyWebhook($request)) {
            abort(403, 'Webhook verification failed');
        }

        return response($request->query('hub_challenge'), 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * POST /webhook/meta — Receive inbound messages from Meta platforms.
     */
    public function receive(Request $request): JsonResponse
    {
        if (! $this->meta->validateSignature($request)) {
            return $this->error('Invalid signature', 403);
        }

        $payload = $request->json()->all();
        $dto = $this->meta->parseInboundPayload($payload);

        if ($dto instanceof InboundMessage) {
            $this->receiver->accept($dto);
        }

        // Meta requires a 200 response within 20 seconds regardless of processing outcome.
        return $this->success(message: 'OK');
    }
}
