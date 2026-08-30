<?php

namespace App\Http\Controllers\Api;

use App\Social\Channels\XChannel;
use App\Social\InboundMessageReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class XWebhookController extends BaseController
{
    public function __construct(
        private readonly XChannel $x,
        private readonly InboundMessageReceiver $receiver,
    ) {}

    /**
     * GET /webhook/x — X CRC challenge response.
     */
    public function verify(Request $request): JsonResponse
    {
        $responseToken = $this->x->verifyCrc($request);

        if (! $responseToken) {
            return $this->error('Missing crc_token', 400);
        }

        return response()->json(['response_token' => $responseToken]);
    }

    /**
     * POST /webhook/x — Receive Account Activity API events.
     */
    public function receive(Request $request): JsonResponse
    {
        if (! $this->x->validateSignature($request)) {
            return $this->error('Invalid signature', 403);
        }

        $payload = $request->json()->all();
        $dto = $this->x->parseInboundPayload($payload);

        if ($dto !== null) {
            $this->receiver->accept($dto);
        }

        // X requires a 200 within a few seconds.
        return $this->success(message: 'OK');
    }
}
