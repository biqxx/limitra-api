<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for Microstore endpoints.
 */
class MicrostoreOpenApi
{
    #[OA\Get(
        path: '/admin/microstores',
        tags: ['Admin - Microstores'],
        summary: 'List Microstores',
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
    public function op1_admin_microstores(): void {}

    #[OA\Get(
        path: '/admin/microstores/{microstore}',
        tags: ['Admin - Microstores'],
        summary: 'Get Microstores details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'microstore', in: 'path', required: true, description: 'Microstore identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op2_admin_microstores__microstore_(): void {}

    #[OA\Post(
        path: '/admin/microstores/{microstore}/moderate',
        tags: ['Admin - Microstores'],
        summary: 'Moderate - Moderate',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'microstore', in: 'path', required: true, description: 'Microstore identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['moderation_status'],
                properties: [
                    new OA\Property(property: 'moderation_status', type: 'string', enum: ['approved', 'rejected', 'suspended]']),
                    new OA\Property(property: 'moderation_notes', type: 'string', nullable: true),
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
    public function op3_admin_microstores__microstore__moderate(): void {}

    #[OA\Patch(
        path: '/admin/microstores/{microstore}/feature',
        tags: ['Admin - Microstores'],
        summary: 'Toggle Featured - Feature',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'microstore', in: 'path', required: true, description: 'Microstore identifier', schema: new OA\Schema(type: 'string')),
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
    public function op4_admin_microstores__microstore__feature(): void {}

    #[OA\Get(
        path: '/microstores',
        tags: ['Microstores - Public'],
        summary: 'List Microstores',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op5_microstores(): void {}

    #[OA\Get(
        path: '/microstores/{slug}',
        tags: ['Microstores - Public'],
        summary: 'Get Microstores details',
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, description: 'Slug identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op6_microstores__slug_(): void {}

    #[OA\Get(
        path: '/microstores/{slug}/products',
        tags: ['Microstores - Public'],
        summary: 'Products - Products',
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, description: 'Slug identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op7_microstores__slug__products(): void {}

    #[OA\Post(
        path: '/microstores/{slug}/clicks',
        tags: ['Microstores - Public'],
        summary: 'Track Click - Clicks',
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, description: 'Slug identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer'),
                    new OA\Property(property: 'session_id', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op8_microstores__slug__clicks(): void {}

    #[OA\Get(
        path: '/my-microstore',
        tags: ['Microstores - Creator Studio'],
        summary: 'Get authenticated creator microstore',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op9_my_microstore(): void {}

    #[OA\Post(
        path: '/my-microstore',
        tags: ['Microstores - Creator Studio'],
        summary: 'Create new creator microstore',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'tagline', type: 'string', nullable: true),
                    new OA\Property(property: 'bio', type: 'string', nullable: true),
                    new OA\Property(property: 'logo_url', type: 'string', nullable: true),
                    new OA\Property(property: 'banner_url', type: 'string', nullable: true),
                    new OA\Property(property: 'theme_color', type: 'string', nullable: true),
                    new OA\Property(property: 'settings', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op10_my_microstore(): void {}

    #[OA\Patch(
        path: '/my-microstore',
        tags: ['Microstores - Creator Studio'],
        summary: 'Update creator microstore branding and profile',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'tagline', type: 'string', nullable: true),
                    new OA\Property(property: 'bio', type: 'string', nullable: true),
                    new OA\Property(property: 'logo_url', type: 'string', nullable: true),
                    new OA\Property(property: 'banner_url', type: 'string', nullable: true),
                    new OA\Property(property: 'theme_color', type: 'string', nullable: true),
                    new OA\Property(property: 'settings', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op11_my_microstore(): void {}

    #[OA\Post(
        path: '/my-microstore/products',
        tags: ['Microstores - Creator Studio'],
        summary: 'Add product to creator microstore showcase',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer'),
                    new OA\Property(property: 'custom_title', type: 'string', nullable: true),
                    new OA\Property(property: 'custom_description', type: 'string', nullable: true),
                    new OA\Property(property: 'sort_order', type: 'integer', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'is_pinned', type: 'boolean', nullable: true),
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
    public function op12_my_microstore_products(): void {}

    #[OA\Patch(
        path: '/my-microstore/products/{product}',
        tags: ['Microstores - Creator Studio'],
        summary: 'Update product showcase order in microstore',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, description: 'Product identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'custom_title', type: 'string', nullable: true),
                    new OA\Property(property: 'custom_description', type: 'string', nullable: true),
                    new OA\Property(property: 'sort_order', type: 'integer', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'is_pinned', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op13_my_microstore_products__product_(): void {}

    #[OA\Delete(
        path: '/my-microstore/products/{product}',
        tags: ['Microstores - Creator Studio'],
        summary: 'Remove product from creator microstore',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, description: 'Product identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op14_my_microstore_products__product_(): void {}

    #[OA\Get(
        path: '/my-microstore/analytics',
        tags: ['Microstores - Creator Studio'],
        summary: 'Get creator microstore performance metrics',
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
    public function op15_my_microstore_analytics(): void {}
}
