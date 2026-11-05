<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for AiAndSupport endpoints.
 */
class AiAndSupportOpenApi
{
    #[OA\Get(
        path: '/notifications',
        tags: ['Notifications'],
        summary: 'List Notifications',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1', 'max:100]'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op1_notifications(): void {}

    #[OA\Get(
        path: '/notifications/unread-count',
        tags: ['Notifications'],
        summary: 'Unread Count - Unread Count',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op2_notifications_unread_count(): void {}

    #[OA\Patch(
        path: '/notifications/{notification}/read',
        tags: ['Notifications'],
        summary: 'Read - Read',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, description: 'Notification identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op3_notifications__notification__read(): void {}

    #[OA\Post(
        path: '/notifications/read-all',
        tags: ['Notifications'],
        summary: 'Read All - Read All',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op4_notifications_read_all(): void {}

    #[OA\Get(
        path: '/notification-preferences',
        tags: ['Notifications'],
        summary: 'Get Notification Preferences details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op5_notification_preferences(): void {}

    #[OA\Put(
        path: '/notification-preferences',
        tags: ['Notifications'],
        summary: 'Update Notification Preferences',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['preferences'],
                properties: [
                    new OA\Property(property: 'preferences', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op6_notification_preferences(): void {}

    #[OA\Post(
        path: '/support/tickets',
        tags: ['Support Tickets'],
        summary: 'Create new Tickets',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op7_support_tickets(): void {}

    #[OA\Get(
        path: '/support/tickets',
        tags: ['Support Tickets'],
        summary: 'List Tickets',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op8_support_tickets(): void {}

    #[OA\Get(
        path: '/support/tickets/{supportTicket}',
        tags: ['Support Tickets'],
        summary: 'Get Tickets details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'supportTicket', in: 'path', required: true, description: 'SupportTicket identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op9_support_tickets__supportTicket_(): void {}

    #[OA\Post(
        path: '/support/tickets/{supportTicket}/messages',
        tags: ['Support Tickets'],
        summary: 'Message - Messages',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'supportTicket', in: 'path', required: true, description: 'SupportTicket identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op10_support_tickets__supportTicket__messages(): void {}

    #[OA\Post(
        path: '/support/tickets/{supportTicket}/close',
        tags: ['Support Tickets'],
        summary: 'Close - Close',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'supportTicket', in: 'path', required: true, description: 'SupportTicket identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op11_support_tickets__supportTicket__close(): void {}

    #[OA\Get(
        path: '/support/attachments/{supportTicketAttachment}',
        tags: ['Support Tickets'],
        summary: 'Get Attachments details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'supportTicketAttachment', in: 'path', required: true, description: 'SupportTicketAttachment identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op12_support_attachments__supportTicketAttachment_(): void {}

    #[OA\Post(
        path: '/ai/conversations',
        tags: ['AI Customer Support'],
        summary: 'Create new Conversations',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'channel', type: 'string', enum: ['web']),
                    new OA\Property(property: 'context', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op13_ai_conversations(): void {}

    #[OA\Get(
        path: '/ai/conversations/{conversation}/messages',
        tags: ['AI Customer Support'],
        summary: 'Messages - Messages',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, description: 'Conversation identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op14_ai_conversations__conversation__messages(): void {}

    #[OA\Post(
        path: '/ai/conversations/{conversation}/messages',
        tags: ['AI Customer Support'],
        summary: 'Message - Messages',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, description: 'Conversation identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['text', 'client_message_id'],
                properties: [
                    new OA\Property(property: 'text', type: 'string', enum: ['1']),
                    new OA\Property(property: 'client_message_id', type: 'string', enum: ['8']),
                    new OA\Property(property: 'async', type: 'boolean'),
                    new OA\Property(property: 'images', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op15_ai_conversations__conversation__messages(): void {}

    #[OA\Post(
        path: '/ai/conversations/{conversation}/handoff',
        tags: ['AI Customer Support'],
        summary: 'Handoff - Handoff',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, description: 'Conversation identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op16_ai_conversations__conversation__handoff(): void {}

    #[OA\Get(
        path: '/ai/conversations/{conversation}/attachments/{attachment}',
        tags: ['AI Customer Support'],
        summary: 'Attachment - Attachments',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, description: 'Conversation identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'attachment', in: 'path', required: true, description: 'Attachment identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op17_ai_conversations__conversation__attachments__attachment_(): void {}

    #[OA\Delete(
        path: '/ai/conversations/{conversation}',
        tags: ['AI Customer Support'],
        summary: 'Delete Conversations',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, description: 'Conversation identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op18_ai_conversations__conversation_(): void {}

    #[OA\Post(
        path: '/webhooks/payments/{provider}',
        tags: ['Webhooks'],
        summary: 'Handle - Payments',
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'provider', in: 'path', required: true, description: 'Provider identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op19_webhooks_payments__provider_(): void {}

    #[OA\Get(
        path: '/webhook/meta',
        tags: ['Webhooks'],
        summary: 'Verify Meta',
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op20_webhook_meta(): void {}

    #[OA\Post(
        path: '/webhook/meta',
        tags: ['Webhooks'],
        summary: 'Receive - Meta',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op21_webhook_meta(): void {}

    #[OA\Get(
        path: '/webhook/x',
        tags: ['Webhooks'],
        summary: 'Verify X',
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op22_webhook_x(): void {}

    #[OA\Post(
        path: '/webhook/x',
        tags: ['Webhooks'],
        summary: 'Receive - X',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op23_webhook_x(): void {}
}
