<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for Admin endpoints.
 */
class AdminOpenApi
{
    #[OA\Get(
        path: '/admin/categories',
        tags: ['Admin - Products & Catalog'],
        summary: 'List Categories',
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
    public function op1_admin_categories(): void {}

    #[OA\Get(
        path: '/admin/products',
        tags: ['Admin - Products & Catalog'],
        summary: 'List Products',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'active', 'archived]'])),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'subcategory_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'brand', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['amazon', 'ebay]'])),
            new OA\Parameter(name: 'stock_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['in_stock', 'out_of_stock', 'low_stock]'])),
            new OA\Parameter(name: 'is_featured', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'is_bestseller', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'trashed', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['without', 'with', 'only]'])),
            new OA\Parameter(name: 'sort_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['id', 'name', 'price', 'stock', 'status', 'created_at', 'updated_at]'])),
            new OA\Parameter(name: 'sort_dir', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc]'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op2_admin_products(): void {}

    #[OA\Post(
        path: '/admin/products',
        tags: ['Admin - Products & Catalog'],
        summary: 'Create new Products',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['category_id', 'name', 'price', 'stock'],
                properties: [
                    new OA\Property(property: 'category_id', type: 'string'),
                    new OA\Property(property: 'subcategory_id', type: 'string', nullable: true),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'brand', type: 'string', nullable: true),
                    new OA\Property(property: 'sku', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'price', type: 'number', enum: ['0]']),
                    new OA\Property(property: 'compare_at_price', type: 'number', nullable: true),
                    new OA\Property(property: 'currency', type: 'string', nullable: true),
                    new OA\Property(property: 'stock', type: 'integer', enum: ['0]']),
                    new OA\Property(property: 'low_stock_threshold', type: 'integer', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['draft', 'active', 'archived]']),
                    new OA\Property(property: 'is_featured', type: 'boolean', nullable: true),
                    new OA\Property(property: 'is_bestseller', type: 'boolean', nullable: true),
                    new OA\Property(property: 'images', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'seo_meta', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'variants', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'specifications', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op3_admin_products(): void {}

    #[OA\Post(
        path: '/admin/products/{product}/restore',
        tags: ['Admin - Products & Catalog'],
        summary: 'Restore Restore',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, description: 'Product identifier', schema: new OA\Schema(type: 'integer')),
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
    public function op4_admin_products__product__restore(): void {}

    #[OA\Get(
        path: '/admin/products/{product}',
        tags: ['Admin - Products & Catalog'],
        summary: 'Get Products details',
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
    public function op5_admin_products__product_(): void {}

    #[OA\Put(
        path: '/admin/products/{product}',
        tags: ['Admin - Products & Catalog'],
        summary: 'Update Products',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, description: 'Product identifier', schema: new OA\Schema(type: 'integer')),
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
    public function op6_admin_products__product_(): void {}

    #[OA\Patch(
        path: '/admin/products/{product}',
        tags: ['Admin - Products & Catalog'],
        summary: 'Update Products',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, description: 'Product identifier', schema: new OA\Schema(type: 'integer')),
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
    public function op7_admin_products__product_(): void {}

    #[OA\Delete(
        path: '/admin/products/{product}',
        tags: ['Admin - Products & Catalog'],
        summary: 'Delete Products',
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
    public function op8_admin_products__product_(): void {}

    #[OA\Get(
        path: '/admin/orders',
        tags: ['Admin - Orders'],
        summary: 'List Orders',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending_payment', 'confirmed', 'processing', 'shipped', 'in_transit', 'delivered', 'cancelled]'])),
            new OA\Parameter(name: 'payment_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['unpaid', 'pending', 'paid', 'failed', 'refunded', 'partially_refunded]'])),
            new OA\Parameter(name: 'cancellation_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['created_at', 'status', 'grand_total]'])),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc]'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1', 'max:100]'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op9_admin_orders(): void {}

    #[OA\Get(
        path: '/admin/orders/{order}',
        tags: ['Admin - Orders'],
        summary: 'Get Orders details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'Order identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op10_admin_orders__order_(): void {}

    #[OA\Get(
        path: '/admin/affiliate-applications',
        tags: ['Admin - Affiliates'],
        summary: 'Applications - Affiliate Applications',
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
    public function op11_admin_affiliate_applications(): void {}

    #[OA\Get(
        path: '/admin/affiliate-applications/{application}',
        tags: ['Admin - Affiliates'],
        summary: 'Show Application - Affiliate Applications',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'application', in: 'path', required: true, description: 'Application identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op12_admin_affiliate_applications__application_(): void {}

    #[OA\Post(
        path: '/admin/affiliate-applications/{application}/approve',
        tags: ['Admin - Affiliates'],
        summary: 'Approve Application - Approve',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'application', in: 'path', required: true, description: 'Application identifier', schema: new OA\Schema(type: 'string')),
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
    public function op13_admin_affiliate_applications__application__approve(): void {}

    #[OA\Post(
        path: '/admin/affiliate-applications/{application}/reject',
        tags: ['Admin - Affiliates'],
        summary: 'Reject Application - Reject',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'application', in: 'path', required: true, description: 'Application identifier', schema: new OA\Schema(type: 'string')),
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
    public function op14_admin_affiliate_applications__application__reject(): void {}

    #[OA\Get(
        path: '/admin/withdrawals',
        tags: ['Admin - Affiliates'],
        summary: 'Withdrawals - Withdrawals',
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
    public function op15_admin_withdrawals(): void {}

    #[OA\Post(
        path: '/admin/withdrawals/{withdrawal}/approve',
        tags: ['Admin - Affiliates'],
        summary: 'Approve Withdrawal - Approve',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, description: 'Withdrawal identifier', schema: new OA\Schema(type: 'string')),
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
    public function op16_admin_withdrawals__withdrawal__approve(): void {}

    #[OA\Post(
        path: '/admin/withdrawals/{withdrawal}/start-processing',
        tags: ['Admin - Affiliates'],
        summary: 'Start Processing Withdrawal - Start Processing',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, description: 'Withdrawal identifier', schema: new OA\Schema(type: 'string')),
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
    public function op17_admin_withdrawals__withdrawal__start_processing(): void {}

    #[OA\Post(
        path: '/admin/withdrawals/{withdrawal}/reject',
        tags: ['Admin - Affiliates'],
        summary: 'Reject Withdrawal - Reject',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, description: 'Withdrawal identifier', schema: new OA\Schema(type: 'string')),
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
    public function op18_admin_withdrawals__withdrawal__reject(): void {}

    #[OA\Post(
        path: '/admin/withdrawals/{withdrawal}/mark-paid',
        tags: ['Admin - Affiliates'],
        summary: 'Mark Paid Withdrawal - Mark Paid',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'withdrawal', in: 'path', required: true, description: 'Withdrawal identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['provider_reference'],
                properties: [
                    new OA\Property(property: 'provider_reference', type: 'string'),
                    new OA\Property(property: 'admin_notes', type: 'string', nullable: true),
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
    public function op19_admin_withdrawals__withdrawal__mark_paid(): void {}

    #[OA\Get(
        path: '/admin/affiliate-rules',
        tags: ['Admin - Affiliates'],
        summary: 'Rules - Affiliate Rules',
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
    public function op20_admin_affiliate_rules(): void {}

    #[OA\Get(
        path: '/admin/affiliate-resources',
        tags: ['Admin - Affiliates'],
        summary: 'Resources - Affiliate Resources',
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
    public function op21_admin_affiliate_resources(): void {}

    #[OA\Patch(
        path: '/admin/affiliate-rules',
        tags: ['Admin - Affiliates'],
        summary: 'Update Rules - Affiliate Rules',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'min_withdrawal_amount', type: 'integer', enum: ['1000', 'max:5000000]']),
                    new OA\Property(property: 'attribution_window_days', type: 'integer', enum: ['1', 'max:180]']),
                    new OA\Property(property: 'qualification_window_days', type: 'integer', enum: ['7', 'max:365]']),
                    new OA\Property(property: 'allowed_redirect_domains', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(type: 'string')),
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
    public function op22_admin_affiliate_rules(): void {}

    #[OA\Post(
        path: '/admin/affiliate-resources',
        tags: ['Admin - Affiliates'],
        summary: 'Store Resource - Affiliate Resources',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'category', type: 'string', nullable: true),
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'is_published', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op23_admin_affiliate_resources(): void {}

    #[OA\Delete(
        path: '/admin/affiliate-resources/{resource}',
        tags: ['Admin - Affiliates'],
        summary: 'Destroy Resource - Affiliate Resources',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'resource', in: 'path', required: true, description: 'Resource identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op24_admin_affiliate_resources__resource_(): void {}

    #[OA\Get(
        path: '/admin/dashboard',
        tags: ['Admin - Dashboard & Health'],
        summary: 'Dashboard - Dashboard',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op25_admin_dashboard(): void {}

    #[OA\Get(
        path: '/admin/health',
        tags: ['Admin - Dashboard & Health'],
        summary: 'Health - Health',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op26_admin_health(): void {}

    #[OA\Get(
        path: '/admin/reports',
        tags: ['Admin - Reports & Queue'],
        summary: 'List Reports',
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
    public function op27_admin_reports(): void {}

    #[OA\Post(
        path: '/admin/reports',
        tags: ['Admin - Reports & Queue'],
        summary: 'Create new Reports',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type'],
                properties: [
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'format', type: 'string', nullable: true),
                    new OA\Property(property: 'timezone', type: 'string', nullable: true),
                    new OA\Property(property: 'date_from', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'date_to', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'limit', type: 'integer', nullable: true, enum: ['1']),
                    new OA\Property(property: 'columns', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'filters', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op28_admin_reports(): void {}

    #[OA\Get(
        path: '/admin/reports/{report}',
        tags: ['Admin - Reports & Queue'],
        summary: 'Get Reports details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, description: 'Report identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op29_admin_reports__report_(): void {}

    #[OA\Post(
        path: '/admin/reports/{report}/retry',
        tags: ['Admin - Reports & Queue'],
        summary: 'Retry Retry',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'report', in: 'path', required: true, description: 'Report identifier', schema: new OA\Schema(type: 'string')),
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
    public function op30_admin_reports__report__retry(): void {}

    #[OA\Get(
        path: '/admin/reports/{report}/download',
        tags: ['Admin - Reports & Queue'],
        summary: 'Download Download file',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, description: 'Report identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op31_admin_reports__report__download(): void {}

    #[OA\Get(
        path: '/admin/users',
        tags: ['Admin - Users & Access'],
        summary: 'List Users',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'role', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op32_admin_users(): void {}

    #[OA\Get(
        path: '/admin/users/{user}',
        tags: ['Admin - Users & Access'],
        summary: 'Get Users details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, description: 'User identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op33_admin_users__user_(): void {}

    #[OA\Get(
        path: '/admin/roles',
        tags: ['Admin - Roles & Permissions'],
        summary: 'List Roles',
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
    public function op34_admin_roles(): void {}

    #[OA\Get(
        path: '/admin/roles/{role}',
        tags: ['Admin - Roles & Permissions'],
        summary: 'Get Roles details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, description: 'Role identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op35_admin_roles__role_(): void {}

    #[OA\Get(
        path: '/admin/permissions',
        tags: ['Admin - Roles & Permissions'],
        summary: 'List Permissions',
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
    public function op36_admin_permissions(): void {}

    #[OA\Get(
        path: '/admin/roles/{role}/members',
        tags: ['Admin - Roles & Permissions'],
        summary: 'List Members',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, description: 'Role identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op37_admin_roles__role__members(): void {}

    #[OA\Get(
        path: '/admin/staff/invitations',
        tags: ['Admin - Users & Access'],
        summary: 'List Invitations',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op38_admin_staff_invitations(): void {}

    #[OA\Post(
        path: '/admin/users',
        tags: ['Admin - Users & Access'],
        summary: 'Create new Users',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'role_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'role', type: 'string', nullable: true),
                    new OA\Property(property: 'invite', type: 'string'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'password', type: 'string'),
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
    public function op39_admin_users(): void {}

    #[OA\Post(
        path: '/admin/roles',
        tags: ['Admin - Roles & Permissions'],
        summary: 'Create new Roles',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'display_name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'display_name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'is_system', type: 'string'),
                    new OA\Property(property: 'is_super_admin', type: 'string'),
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
    public function op40_admin_roles(): void {}

    #[OA\Patch(
        path: '/admin/roles/{role}',
        tags: ['Admin - Roles & Permissions'],
        summary: 'Update Roles',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, description: 'Role identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'display_name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'is_system', type: 'string'),
                    new OA\Property(property: 'is_super_admin', type: 'string'),
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
    public function op41_admin_roles__role_(): void {}

    #[OA\Delete(
        path: '/admin/roles/{role}',
        tags: ['Admin - Roles & Permissions'],
        summary: 'Delete Roles',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, description: 'Role identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op42_admin_roles__role_(): void {}

    #[OA\Post(
        path: '/admin/roles/{role}/members',
        tags: ['Admin - Roles & Permissions'],
        summary: 'Create new Members',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, description: 'Role identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_id', 'name', 'email'],
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
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
    public function op43_admin_roles__role__members(): void {}

    #[OA\Delete(
        path: '/admin/roles/{role}/members/{user}',
        tags: ['Admin - Roles & Permissions'],
        summary: 'Delete Members',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'path', required: true, description: 'Role identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user', in: 'path', required: true, description: 'User identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op44_admin_roles__role__members__user_(): void {}

    #[OA\Patch(
        path: '/admin/staff/{user}/role',
        tags: ['Admin - Users & Access'],
        summary: 'Replace - Role',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, description: 'User identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['role_id'],
                properties: [
                    new OA\Property(property: 'role_id', type: 'integer'),
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
    public function op45_admin_staff__user__role(): void {}

    #[OA\Post(
        path: '/admin/staff/invitations',
        tags: ['Admin - Users & Access'],
        summary: 'Create new Invitations',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'role_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'role', type: 'string', nullable: true),
                    new OA\Property(property: 'invite', type: 'string'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'password', type: 'string'),
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
    public function op46_admin_staff_invitations(): void {}

    #[OA\Post(
        path: '/admin/staff/invitations/{staffInvitation}/resend',
        tags: ['Admin - Users & Access'],
        summary: 'Resend - Resend',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'staffInvitation', in: 'path', required: true, description: 'StaffInvitation identifier', schema: new OA\Schema(type: 'string')),
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
    public function op47_admin_staff_invitations__staffInvitation__resend(): void {}

    #[OA\Delete(
        path: '/admin/staff/invitations/{staffInvitation}',
        tags: ['Admin - Users & Access'],
        summary: 'Delete Invitations',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'staffInvitation', in: 'path', required: true, description: 'StaffInvitation identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op48_admin_staff_invitations__staffInvitation_(): void {}

    #[OA\Patch(
        path: '/admin/users/{user}/role',
        tags: ['Admin - Users & Access'],
        summary: 'Update Role - Role',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, description: 'User identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['role'],
                properties: [
                    new OA\Property(property: 'role', type: 'string'),
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
    public function op49_admin_users__user__role(): void {}

    #[OA\Delete(
        path: '/admin/users/{user}',
        tags: ['Admin - Users & Access'],
        summary: 'Delete Users',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, description: 'User identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op50_admin_users__user_(): void {}

    #[OA\Patch(
        path: '/admin/users/{user}/status',
        tags: ['Admin - Users & Access'],
        summary: 'Update Status - Status',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, description: 'User identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                    new OA\Property(property: 'suspended_until', type: 'string', format: 'date', nullable: true),
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
    public function op51_admin_users__user__status(): void {}

    #[OA\Post(
        path: '/admin/users/{user}/suspend',
        tags: ['Admin - Users & Access'],
        summary: 'Suspend - Suspend',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, description: 'User identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string'),
                    new OA\Property(property: 'suspended_until', type: 'string', format: 'date', nullable: true),
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
    public function op52_admin_users__user__suspend(): void {}

    #[OA\Post(
        path: '/admin/users/{user}/restore',
        tags: ['Admin - Users & Access'],
        summary: 'Restore Restore',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, description: 'User identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'note', type: 'string', nullable: true),
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
    public function op53_admin_users__user__restore(): void {}

    #[OA\Post(
        path: '/admin/users/{user}/password-reset',
        tags: ['Admin - Users & Access'],
        summary: 'Password Reset - Password Reset',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, description: 'User identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'channel', type: 'string'),
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
    public function op54_admin_users__user__password_reset(): void {}

    #[OA\Patch(
        path: '/admin/orders/{order}',
        tags: ['Admin - Orders'],
        summary: 'Update Orders',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'Order identifier', schema: new OA\Schema(type: 'integer')),
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
    public function op55_admin_orders__order_(): void {}

    #[OA\Delete(
        path: '/admin/orders/{order}',
        tags: ['Admin - Orders'],
        summary: 'Delete Orders',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'Order identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op56_admin_orders__order_(): void {}

    #[OA\Patch(
        path: '/admin/orders/{order}/status',
        tags: ['Admin - Orders'],
        summary: 'Update Status - Status',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'Order identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'note'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['processing', 'in_transit', 'delivered]']),
                    new OA\Property(property: 'note', type: 'string'),
                    new OA\Property(property: 'location', type: 'string', nullable: true),
                    new OA\Property(property: 'occurred_at', type: 'string', format: 'date', nullable: true),
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
    public function op57_admin_orders__order__status(): void {}

    #[OA\Post(
        path: '/admin/orders/{order}/ship',
        tags: ['Admin - Orders'],
        summary: 'Ship - Ship',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'Order identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['courier', 'tracking_number'],
                properties: [
                    new OA\Property(property: 'courier', type: 'string'),
                    new OA\Property(property: 'tracking_number', type: 'string'),
                    new OA\Property(property: 'estimated_delivery_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'location', type: 'string', nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
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
    public function op58_admin_orders__order__ship(): void {}

    #[OA\Post(
        path: '/admin/orders/{order}/refund',
        tags: ['Admin - Orders'],
        summary: 'Refund - Refund',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'Order identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'method', 'reason'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', enum: ['0.01']),
                    new OA\Property(property: 'method', type: 'string', enum: ['original_payment', 'wallet']),
                    new OA\Property(property: 'reason', type: 'string', enum: ['3']),
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
    public function op59_admin_orders__order__refund(): void {}

    #[OA\Patch(
        path: '/admin/reviews/{review}/moderate',
        tags: ['Admin - Support & Operations'],
        summary: 'Moderate - Moderate',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'review', in: 'path', required: true, description: 'Review identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'reason'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'published', 'rejected']),
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
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
    public function op60_admin_reviews__review__moderate(): void {}

    #[OA\Get(
        path: '/admin/returns',
        tags: ['Admin - Returns & Refunds'],
        summary: 'List Returns',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected', 'cancelled', 'received', 'completed]'])),
            new OA\Parameter(name: 'resolution', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['refund', 'store_credit', 'replacement]'])),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1', 'max:100]'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op61_admin_returns(): void {}

    #[OA\Patch(
        path: '/admin/returns/{returnRequest}',
        tags: ['Admin - Returns & Refunds'],
        summary: 'Update Returns',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'returnRequest', in: 'path', required: true, description: 'ReturnRequest identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'reason', 'items'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['approved', 'rejected', 'received', 'completed']),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                    new OA\Property(property: 'items', type: 'array', nullable: true, enum: ['1'], items: new OA\Items(type: 'string')),
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
    public function op62_admin_returns__returnRequest_(): void {}

    #[OA\Post(
        path: '/admin/returns/{returnRequest}/refund',
        tags: ['Admin - Returns & Refunds'],
        summary: 'Refund - Refund',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'returnRequest', in: 'path', required: true, description: 'ReturnRequest identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'method', 'reason'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', enum: ['0.01']),
                    new OA\Property(property: 'method', type: 'string', enum: ['original_payment', 'wallet']),
                    new OA\Property(property: 'reason', type: 'string', enum: ['3']),
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
    public function op63_admin_returns__returnRequest__refund(): void {}

    #[OA\Get(
        path: '/admin/refunds',
        tags: ['Admin - Returns & Refunds'],
        summary: 'List Refunds',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['initiating', 'pending', 'processing', 'needs_attention', 'processed', 'failed]'])),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['return', 'late_payment]'])),
            new OA\Parameter(name: 'provider', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'attention_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['created_at', 'updated_at', 'amount', 'status', 'last_reconciled_at]'])),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc]'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1', 'max:100]'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op64_admin_refunds(): void {}

    #[OA\Get(
        path: '/admin/refunds/{refund}',
        tags: ['Admin - Returns & Refunds'],
        summary: 'Get Refunds details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'refund', in: 'path', required: true, description: 'Refund identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op65_admin_refunds__refund_(): void {}

    #[OA\Post(
        path: '/admin/refunds/{refund}/retry',
        tags: ['Admin - Returns & Refunds'],
        summary: 'Retry Retry',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'refund', in: 'path', required: true, description: 'Refund identifier', schema: new OA\Schema(type: 'string')),
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
    public function op66_admin_refunds__refund__retry(): void {}

    #[OA\Post(
        path: '/admin/refunds/{refund}/resolve',
        tags: ['Admin - Returns & Refunds'],
        summary: 'Resolve - Resolve',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'refund', in: 'path', required: true, description: 'Refund identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'note', 'provider_reference'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['processed', 'failed']),
                    new OA\Property(property: 'note', type: 'string', enum: ['3']),
                    new OA\Property(property: 'provider_reference', type: 'string', nullable: true),
                    new OA\Property(property: 'provider_refund_id', type: 'string', nullable: true),
                    new OA\Property(property: 'processed_at', type: 'string', format: 'date', nullable: true),
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
    public function op67_admin_refunds__refund__resolve(): void {}

    #[OA\Get(
        path: '/admin/settings',
        tags: ['Admin - Settings'],
        summary: 'List Settings',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'group', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op68_admin_settings(): void {}

    #[OA\Get(
        path: '/admin/settings/{businessSetting}/history',
        tags: ['Admin - Settings'],
        summary: 'History - History',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'businessSetting', in: 'path', required: true, description: 'BusinessSetting identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1', 'max:100]'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op69_admin_settings__businessSetting__history(): void {}

    #[OA\Patch(
        path: '/admin/settings',
        tags: ['Admin - Settings'],
        summary: 'Update Settings',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['settings'],
                properties: [
                    new OA\Property(property: 'settings', type: 'array', enum: ['1'], items: new OA\Items(type: 'string')),
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
    public function op70_admin_settings(): void {}

    #[OA\Get(
        path: '/admin/queue/status',
        tags: ['Admin - Reports & Queue'],
        summary: 'Status - Status',
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
    public function op71_admin_queue_status(): void {}

    #[OA\Get(
        path: '/admin/queue/metrics',
        tags: ['Admin - Reports & Queue'],
        summary: 'Metrics - Metrics',
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
    public function op72_admin_queue_metrics(): void {}

    #[OA\Get(
        path: '/admin/queue/failed-jobs',
        tags: ['Admin - Reports & Queue'],
        summary: 'Failed - Failed Jobs',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'starting_at', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['-1]'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op73_admin_queue_failed_jobs(): void {}

    #[OA\Post(
        path: '/admin/queue/failed-jobs/{id}/retry',
        tags: ['Admin - Reports & Queue'],
        summary: 'Retry Retry',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Id identifier', schema: new OA\Schema(type: 'integer')),
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
    public function op74_admin_queue_failed_jobs__id__retry(): void {}

    #[OA\Get(
        path: '/admin/mail-preview',
        tags: ['Admin - Support & Operations'],
        summary: '  Invoke - Mail Preview',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op75_admin_mail_preview(): void {}

    #[OA\Get(
        path: '/admin/notifications',
        tags: ['Admin - Notifications'],
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
    public function op76_admin_notifications(): void {}

    #[OA\Get(
        path: '/admin/notifications/unread-count',
        tags: ['Admin - Notifications'],
        summary: 'Unread Count - Unread Count',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op77_admin_notifications_unread_count(): void {}

    #[OA\Patch(
        path: '/admin/notifications/{notification}/read',
        tags: ['Admin - Notifications'],
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
    public function op78_admin_notifications__notification__read(): void {}

    #[OA\Post(
        path: '/admin/notifications/read-all',
        tags: ['Admin - Notifications'],
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
    public function op79_admin_notifications_read_all(): void {}

    #[OA\Get(
        path: '/admin/notification-settings',
        tags: ['Admin - Settings'],
        summary: 'List Notification Settings',
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
    public function op80_admin_notification_settings(): void {}

    #[OA\Patch(
        path: '/admin/notification-settings/{event}',
        tags: ['Admin - Settings'],
        summary: 'Update Notification Settings',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, description: 'Event identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['default_channels'],
                properties: [
                    new OA\Property(property: 'default_channels', type: 'array', enum: ['1]'], items: new OA\Items(type: 'string')),
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
    public function op81_admin_notification_settings__event_(): void {}

    #[OA\Get(
        path: '/admin/support/tickets',
        tags: ['Admin - Support & Operations'],
        summary: 'List Tickets',
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
    public function op82_admin_support_tickets(): void {}

    #[OA\Patch(
        path: '/admin/support/tickets/{supportTicket}',
        tags: ['Admin - Support & Operations'],
        summary: 'Update Tickets',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'supportTicket', in: 'path', required: true, description: 'SupportTicket identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'priority', type: 'string'),
                    new OA\Property(property: 'assignee_id', type: 'integer', nullable: true),
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
    public function op83_admin_support_tickets__supportTicket_(): void {}

    #[OA\Get(
        path: '/admin/reward-campaigns',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'List Reward Campaigns',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1', 'max:100]'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op84_admin_reward_campaigns(): void {}

    #[OA\Get(
        path: '/admin/reward-campaigns/{campaign}/wins',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Wins - Wins',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'campaign', in: 'path', required: true, description: 'Campaign identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'prize_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1', 'max:100]'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op85_admin_reward_campaigns__campaign__wins(): void {}

    #[OA\Post(
        path: '/admin/reward-campaigns',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Create new Reward Campaigns',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'status', 'starts_at', 'ends_at', 'eligibility', 'coupon_expiry_days', 'prizes'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'starts_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'ends_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'eligibility', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'coupon_expiry_days', type: 'integer', enum: ['1']),
                    new OA\Property(property: 'prizes', type: 'array', enum: ['1'], items: new OA\Items(type: 'string')),
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
    public function op86_admin_reward_campaigns(): void {}

    #[OA\Patch(
        path: '/admin/reward-campaigns/{campaign}',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Update Reward Campaigns',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'campaign', in: 'path', required: true, description: 'Campaign identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'starts_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'ends_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'eligibility', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'coupon_expiry_days', type: 'integer', enum: ['1']),
                    new OA\Property(property: 'prizes', type: 'array', enum: ['1'], items: new OA\Items(type: 'string')),
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
    public function op87_admin_reward_campaigns__campaign_(): void {}

    #[OA\Get(
        path: '/admin/sourcing/sources',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Sources - Sources',
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
    public function op88_admin_sourcing_sources(): void {}

    #[OA\Get(
        path: '/admin/sourcing/search',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Search - Search',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['2'])),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: ['1'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op89_admin_sourcing_search(): void {}

    #[OA\Post(
        path: '/admin/sourcing/enhance',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Enhance - Enhance',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['source', 'external_product_id'],
                properties: [
                    new OA\Property(property: 'source', type: 'string'),
                    new OA\Property(property: 'external_product_id', type: 'string'),
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
    public function op90_admin_sourcing_enhance(): void {}

    #[OA\Get(
        path: '/admin/sourcing/jobs',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Jobs - Jobs',
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
    public function op91_admin_sourcing_jobs(): void {}

    #[OA\Post(
        path: '/admin/sourcing/jobs',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Queue - Jobs',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['products'],
                properties: [
                    new OA\Property(property: 'products', type: 'array', enum: ['1'], items: new OA\Items(type: 'string')),
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
    public function op92_admin_sourcing_jobs(): void {}

    #[OA\Get(
        path: '/admin/sourcing/imports',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'List Imports',
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
    public function op93_admin_sourcing_imports(): void {}

    #[OA\Post(
        path: '/admin/sourcing/imports',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Create new Imports',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['source', 'external_product_id', 'category_id', 'name'],
                properties: [
                    new OA\Property(property: 'source', type: 'string'),
                    new OA\Property(property: 'external_product_id', type: 'string'),
                    new OA\Property(property: 'category_id', type: 'integer'),
                    new OA\Property(property: 'subcategory_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'brand', type: 'string', nullable: true),
                    new OA\Property(property: 'sku', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'specifications', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op94_admin_sourcing_imports(): void {}

    #[OA\Get(
        path: '/admin/sourcing/imports/{productSource}/pricing',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Get Pricing details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'productSource', in: 'path', required: true, description: 'ProductSource identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op95_admin_sourcing_imports__productSource__pricing(): void {}

    #[OA\Post(
        path: '/admin/sourcing/imports/{productSource}/pricing/recalculate',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Recalculate - Recalculate',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'productSource', in: 'path', required: true, description: 'ProductSource identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'supplier_price', type: 'number'),
                    new OA\Property(property: 'supplier_currency', type: 'string'),
                    new OA\Property(property: 'weight_lb', type: 'number'),
                    new OA\Property(property: 'delivery_days_min', type: 'integer', enum: ['1']),
                    new OA\Property(property: 'delivery_days_max', type: 'integer', enum: ['1']),
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
    public function op96_admin_sourcing_imports__productSource__pricing_recalculate(): void {}

    #[OA\Post(
        path: '/admin/sourcing/imports/{productSource}/pricing/approve',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Approve Approve',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'productSource', in: 'path', required: true, description: 'ProductSource identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'quote_id', type: 'integer'),
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
    public function op97_admin_sourcing_imports__productSource__pricing_approve(): void {}

    #[OA\Post(
        path: '/admin/inventory/adjustments',
        tags: ['Admin - Inventory'],
        summary: 'Store Adjustment - Adjustments',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id', 'quantity_delta', 'reason'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'variant_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'quantity_delta', type: 'integer'),
                    new OA\Property(property: 'reason', type: 'string', enum: ['restock', 'shrinkage', 'damage', 'audit_correction', 'return_restock', 'supplier_receipt', 'manual_adjustment]']),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
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
    public function op98_admin_inventory_adjustments(): void {}

    #[OA\Patch(
        path: '/admin/inventory/{product}/threshold',
        tags: ['Admin - Inventory'],
        summary: 'Update Threshold - Threshold',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, description: 'Product identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['low_stock_threshold'],
                properties: [
                    new OA\Property(property: 'variant_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'low_stock_threshold', type: 'integer', enum: ['0', 'max:100000]']),
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
    public function op99_admin_inventory__product__threshold(): void {}

    #[OA\Get(
        path: '/admin/ai/metrics',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Metrics - Metrics',
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
    public function op100_admin_ai_metrics(): void {}

    #[OA\Get(
        path: '/admin/ai/settings',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Settings - Settings',
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
    public function op101_admin_ai_settings(): void {}

    #[OA\Get(
        path: '/admin/ai/conversations',
        tags: ['Admin - AI & Knowledge'],
        summary: 'List Conversations',
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
    public function op102_admin_ai_conversations(): void {}

    #[OA\Get(
        path: '/admin/ai/conversations/{conversation}',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Get Conversations details',
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
    public function op103_admin_ai_conversations__conversation_(): void {}

    #[OA\Patch(
        path: '/admin/ai/settings',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Update Settings - Settings',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'primary_model', type: 'string', nullable: true, enum: ['3', 'max:255]']),
                    new OA\Property(property: 'fallback_model', type: 'string', nullable: true, enum: ['3', 'max:255]']),
                    new OA\Property(property: 'high_reasoning_model', type: 'string', nullable: true, enum: ['3', 'max:255]']),
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
    public function op104_admin_ai_settings(): void {}

    #[OA\Patch(
        path: '/admin/ai/conversations/{conversation}',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Update Conversations',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, description: 'Conversation identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
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
    public function op105_admin_ai_conversations__conversation_(): void {}

    #[OA\Put(
        path: '/admin/ai/conversations/{conversation}',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Update Conversations',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, description: 'Conversation identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
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
    public function op106_admin_ai_conversations__conversation_(): void {}

    #[OA\Get(
        path: '/admin/ai/knowledge',
        tags: ['Admin - AI & Knowledge'],
        summary: 'List Knowledge',
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
    public function op107_admin_ai_knowledge(): void {}

    #[OA\Post(
        path: '/admin/ai/knowledge',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Create new Knowledge',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'content'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'category', type: 'string', nullable: true),
                    new OA\Property(property: 'content', type: 'string'),
                    new OA\Property(property: 'metadata', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op108_admin_ai_knowledge(): void {}

    #[OA\Get(
        path: '/admin/ai/knowledge/{document}',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Get Knowledge details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'document', in: 'path', required: true, description: 'Document identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op109_admin_ai_knowledge__document_(): void {}

    #[OA\Put(
        path: '/admin/ai/knowledge/{document}',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Update Knowledge',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'document', in: 'path', required: true, description: 'Document identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string'),
                    new OA\Property(property: 'category', type: 'string'),
                    new OA\Property(property: 'content', type: 'string'),
                    new OA\Property(property: 'metadata', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op110_admin_ai_knowledge__document_(): void {}

    #[OA\Patch(
        path: '/admin/ai/knowledge/{document}',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Update Knowledge',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'document', in: 'path', required: true, description: 'Document identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string'),
                    new OA\Property(property: 'category', type: 'string'),
                    new OA\Property(property: 'content', type: 'string'),
                    new OA\Property(property: 'metadata', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op111_admin_ai_knowledge__document_(): void {}

    #[OA\Post(
        path: '/admin/ai/knowledge/{document}/index',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Index Document - Index',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'document', in: 'path', required: true, description: 'Document identifier', schema: new OA\Schema(type: 'string')),
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
    public function op112_admin_ai_knowledge__document__index(): void {}

    #[OA\Delete(
        path: '/admin/ai/knowledge/{document}',
        tags: ['Admin - AI & Knowledge'],
        summary: 'Delete Knowledge',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'document', in: 'path', required: true, description: 'Document identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op113_admin_ai_knowledge__document_(): void {}

    #[OA\Get(
        path: '/admin/content',
        tags: ['Admin - Content & CMS'],
        summary: 'List Content',
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
    public function op114_admin_content(): void {}

    #[OA\Get(
        path: '/admin/content/{entry}',
        tags: ['Admin - Content & CMS'],
        summary: 'Admin Show - Content',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'entry', in: 'path', required: true, description: 'Entry identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op115_admin_content__entry_(): void {}

    #[OA\Post(
        path: '/admin/content',
        tags: ['Admin - Content & CMS'],
        summary: 'Create new Content',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'channel', type: 'string', nullable: true),
                    new OA\Property(property: 'locale', type: 'string', nullable: true),
                    new OA\Property(property: 'body', type: 'string', nullable: true),
                    new OA\Property(property: 'seo_title', type: 'string', nullable: true),
                    new OA\Property(property: 'seo_description', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['draft', 'published', 'scheduled]']),
                    new OA\Property(property: 'scheduled_at', type: 'string', format: 'date', nullable: true),
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
    public function op116_admin_content(): void {}

    #[OA\Put(
        path: '/admin/content/{entry}',
        tags: ['Admin - Content & CMS'],
        summary: 'Update Content',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'entry', in: 'path', required: true, description: 'Entry identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'body', type: 'string', nullable: true),
                    new OA\Property(property: 'seo_title', type: 'string', nullable: true),
                    new OA\Property(property: 'seo_description', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['draft', 'published', 'scheduled]']),
                    new OA\Property(property: 'scheduled_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'change_summary', type: 'string', nullable: true),
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
    public function op117_admin_content__entry_(): void {}

    #[OA\Post(
        path: '/admin/content/{entry}/publish',
        tags: ['Admin - Content & CMS'],
        summary: 'Publish - Publish',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'entry', in: 'path', required: true, description: 'Entry identifier', schema: new OA\Schema(type: 'string')),
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
    public function op118_admin_content__entry__publish(): void {}

    #[OA\Get(
        path: '/admin/content/{entry}/revisions',
        tags: ['Admin - Content & CMS'],
        summary: 'Revisions - Revisions',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'entry', in: 'path', required: true, description: 'Entry identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op119_admin_content__entry__revisions(): void {}

    #[OA\Post(
        path: '/admin/content/{entry}/revisions/{revision}/restore',
        tags: ['Admin - Content & CMS'],
        summary: 'Restore Revision - Restore',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'entry', in: 'path', required: true, description: 'Entry identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'revision', in: 'path', required: true, description: 'Revision identifier', schema: new OA\Schema(type: 'string')),
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
    public function op120_admin_content__entry__revisions__revision__restore(): void {}

    #[OA\Get(
        path: '/admin/blog/posts',
        tags: ['Admin - Content & CMS'],
        summary: 'Admin Index - Posts',
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
    public function op121_admin_blog_posts(): void {}

    #[OA\Post(
        path: '/admin/blog/posts',
        tags: ['Admin - Content & CMS'],
        summary: 'Create new Posts',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'content'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'excerpt', type: 'string', nullable: true),
                    new OA\Property(property: 'content', type: 'string'),
                    new OA\Property(property: 'cover_image_url', type: 'string', nullable: true),
                    new OA\Property(property: 'category', type: 'string', nullable: true),
                    new OA\Property(property: 'tags', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['draft', 'published', 'scheduled]']),
                    new OA\Property(property: 'scheduled_at', type: 'string', format: 'date', nullable: true),
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
    public function op122_admin_blog_posts(): void {}

    #[OA\Patch(
        path: '/admin/blog/posts/{post}',
        tags: ['Admin - Content & CMS'],
        summary: 'Update Posts',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, description: 'Post identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'excerpt', type: 'string', nullable: true),
                    new OA\Property(property: 'content', type: 'string'),
                    new OA\Property(property: 'cover_image_url', type: 'string', nullable: true),
                    new OA\Property(property: 'category', type: 'string', nullable: true),
                    new OA\Property(property: 'tags', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['draft', 'published', 'scheduled]']),
                    new OA\Property(property: 'scheduled_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'change_summary', type: 'string', nullable: true),
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
    public function op123_admin_blog_posts__post_(): void {}

    #[OA\Post(
        path: '/admin/blog/posts/{post}/publish',
        tags: ['Admin - Content & CMS'],
        summary: 'Publish - Publish',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, description: 'Post identifier', schema: new OA\Schema(type: 'string')),
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
    public function op124_admin_blog_posts__post__publish(): void {}

    #[OA\Delete(
        path: '/admin/blog/posts/{post}',
        tags: ['Admin - Content & CMS'],
        summary: 'Delete Posts',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, description: 'Post identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op125_admin_blog_posts__post_(): void {}

    #[OA\Get(
        path: '/admin/blog/posts/{post}/revisions',
        tags: ['Admin - Content & CMS'],
        summary: 'Revisions - Revisions',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, description: 'Post identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op126_admin_blog_posts__post__revisions(): void {}

    #[OA\Post(
        path: '/admin/blog/posts/{post}/revisions/{revision}/restore',
        tags: ['Admin - Content & CMS'],
        summary: 'Restore Revision - Restore',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, description: 'Post identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'revision', in: 'path', required: true, description: 'Revision identifier', schema: new OA\Schema(type: 'string')),
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
    public function op127_admin_blog_posts__post__revisions__revision__restore(): void {}

    #[OA\Get(
        path: '/admin/media',
        tags: ['Admin - Content & CMS'],
        summary: 'List Media',
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
    public function op128_admin_media(): void {}

    #[OA\Post(
        path: '/admin/media',
        tags: ['Admin - Content & CMS'],
        summary: 'Create new Media',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'folder', type: 'string', nullable: true),
                        new OA\Property(property: 'title', type: 'string', nullable: true),
                        new OA\Property(property: 'alt', type: 'string', nullable: true),
                        new OA\Property(property: 'tags', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op129_admin_media(): void {}

    #[OA\Get(
        path: '/admin/media/{media}',
        tags: ['Admin - Content & CMS'],
        summary: 'Get Media details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'media', in: 'path', required: true, description: 'Media identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op130_admin_media__media_(): void {}

    #[OA\Patch(
        path: '/admin/media/{media}',
        tags: ['Admin - Content & CMS'],
        summary: 'Update Media',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'media', in: 'path', required: true, description: 'Media identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'alt', type: 'string', nullable: true),
                    new OA\Property(property: 'folder', type: 'string', nullable: true),
                    new OA\Property(property: 'tags', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op131_admin_media__media_(): void {}

    #[OA\Delete(
        path: '/admin/media/{media}',
        tags: ['Admin - Content & CMS'],
        summary: 'Delete Media',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'media', in: 'path', required: true, description: 'Media identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op132_admin_media__media_(): void {}

    #[OA\Get(
        path: '/admin/videos',
        tags: ['Admin - Content & CMS'],
        summary: 'Admin Index - Videos',
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
    public function op133_admin_videos(): void {}

    #[OA\Post(
        path: '/admin/videos',
        tags: ['Admin - Content & CMS'],
        summary: 'Create new Videos',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'url'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'url', type: 'string'),
                    new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true),
                    new OA\Property(property: 'category', type: 'string', nullable: true),
                    new OA\Property(property: 'duration_seconds', type: 'integer', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'is_featured', type: 'boolean', nullable: true),
                    new OA\Property(property: 'tagged_product_ids', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['draft', 'published]']),
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
    public function op134_admin_videos(): void {}

    #[OA\Patch(
        path: '/admin/videos/{video}',
        tags: ['Admin - Content & CMS'],
        summary: 'Update Videos',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'video', in: 'path', required: true, description: 'Video identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'url', type: 'string'),
                    new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true),
                    new OA\Property(property: 'category', type: 'string', nullable: true),
                    new OA\Property(property: 'duration_seconds', type: 'integer', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'is_featured', type: 'boolean', nullable: true),
                    new OA\Property(property: 'tagged_product_ids', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['draft', 'published]']),
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
    public function op135_admin_videos__video_(): void {}

    #[OA\Delete(
        path: '/admin/videos/{video}',
        tags: ['Admin - Content & CMS'],
        summary: 'Delete Videos',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'video', in: 'path', required: true, description: 'Video identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op136_admin_videos__video_(): void {}

    #[OA\Get(
        path: '/admin/jobs',
        tags: ['Admin - Careers & Jobs'],
        summary: 'Admin Jobs - Jobs',
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
    public function op137_admin_jobs(): void {}

    #[OA\Post(
        path: '/admin/jobs',
        tags: ['Admin - Careers & Jobs'],
        summary: 'Store Job - Jobs',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'description'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'department', type: 'string', nullable: true),
                    new OA\Property(property: 'location', type: 'string', nullable: true),
                    new OA\Property(property: 'type', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'requirements', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['open', 'closed]']),
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
    public function op138_admin_jobs(): void {}

    #[OA\Patch(
        path: '/admin/jobs/{job}',
        tags: ['Admin - Careers & Jobs'],
        summary: 'Update Job - Jobs',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, description: 'Job identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'department', type: 'string', nullable: true),
                    new OA\Property(property: 'location', type: 'string', nullable: true),
                    new OA\Property(property: 'type', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'requirements', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true, enum: ['open', 'closed]']),
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
    public function op139_admin_jobs__job_(): void {}

    #[OA\Get(
        path: '/admin/job-applications',
        tags: ['Admin - Careers & Jobs'],
        summary: 'Applications - Job Applications',
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
    public function op140_admin_job_applications(): void {}

    #[OA\Patch(
        path: '/admin/job-applications/{application}',
        tags: ['Admin - Careers & Jobs'],
        summary: 'Update Application Status - Job Applications',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'application', in: 'path', required: true, description: 'Application identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'reviewed', 'shortlisted', 'rejected]']),
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
    public function op141_admin_job_applications__application_(): void {}

    #[OA\Get(
        path: '/admin/job-applications/{application}/cv',
        tags: ['Admin - Careers & Jobs'],
        summary: 'Download Cv - Cv',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'application', in: 'path', required: true, description: 'Application identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op142_admin_job_applications__application__cv(): void {}

    #[OA\Get(
        path: '/admin/procurement/orders',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'List Orders',
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
    public function op143_admin_procurement_orders(): void {}

    #[OA\Get(
        path: '/admin/procurement/orders/{order}',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Get Orders details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'Order identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op144_admin_procurement_orders__order_(): void {}

    #[OA\Post(
        path: '/admin/procurement/orders',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Create new Orders',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'order_item_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'supplier', type: 'string'),
                    new OA\Property(property: 'external_supplier_order_id', type: 'string', nullable: true),
                    new OA\Property(property: 'procurement_cost_amount', type: 'number', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'procurement_cost_currency', type: 'string', nullable: true),
                    new OA\Property(property: 'exchange_rate', type: 'number', nullable: true),
                    new OA\Property(property: 'local_cost_amount', type: 'number', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'shipping_cost_amount', type: 'number', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'customs_cost_amount', type: 'number', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'weight_kg', type: 'number', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'volumetric_weight_kg', type: 'number', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'carrier', type: 'string', nullable: true),
                    new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                    new OA\Property(property: 'metadata', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op145_admin_procurement_orders(): void {}

    #[OA\Patch(
        path: '/admin/procurement/orders/{order}/status',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Update Status - Status',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'Order identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'description'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', format: 'date', enum: ['pending', 'quoted', 'purchased', 'received_at_warehouse', 'in_inspection', 'consolidated', 'dispatched', 'delivered', 'cancelled', 'refunded]']),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'location', type: 'string', nullable: true),
                    new OA\Property(property: 'carrier', type: 'string', nullable: true),
                    new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
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
    public function op146_admin_procurement_orders__order__status(): void {}

    #[OA\Post(
        path: '/admin/procurement/orders/{order}/sync',
        tags: ['Admin - Sourcing & Procurement'],
        summary: 'Sync - Sync',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
            new OA\Parameter(name: 'order', in: 'path', required: true, description: 'Order identifier', schema: new OA\Schema(type: 'integer')),
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
    public function op147_admin_procurement_orders__order__sync(): void {}

    #[OA\Get(
        path: '/admin/delivery-zones',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Zones - Delivery Zones',
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
    public function op148_admin_delivery_zones(): void {}

    #[OA\Get(
        path: '/admin/delivery-methods',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Methods - Delivery Methods',
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
    public function op149_admin_delivery_methods(): void {}

    #[OA\Get(
        path: '/admin/pickup-locations',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Pickups - Pickup Locations',
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
    public function op150_admin_pickup_locations(): void {}

    #[OA\Post(
        path: '/admin/delivery-zones',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Store Zone - Delivery Zones',
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
    public function op151_admin_delivery_zones(): void {}

    #[OA\Patch(
        path: '/admin/delivery-zones/{zone}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Update Zone - Delivery Zones',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'zone', in: 'path', required: true, description: 'Zone identifier', schema: new OA\Schema(type: 'string')),
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
    public function op152_admin_delivery_zones__zone_(): void {}

    #[OA\Post(
        path: '/admin/delivery-methods',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Store Method - Delivery Methods',
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
    public function op153_admin_delivery_methods(): void {}

    #[OA\Patch(
        path: '/admin/delivery-methods/{method}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Update Method - Delivery Methods',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'method', in: 'path', required: true, description: 'Method identifier', schema: new OA\Schema(type: 'string')),
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
    public function op154_admin_delivery_methods__method_(): void {}

    #[OA\Put(
        path: '/admin/delivery-zones/{zone}/methods/{method}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Set Zone Method - Methods',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'zone', in: 'path', required: true, description: 'Zone identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'method', in: 'path', required: true, description: 'Method identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['fee', 'estimated_days_min', 'estimated_days_max'],
                properties: [
                    new OA\Property(property: 'fee', type: 'number', enum: ['0]']),
                    new OA\Property(property: 'free_shipping_threshold', type: 'number', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'minimum_order', type: 'number', nullable: true, enum: ['0]']),
                    new OA\Property(property: 'estimated_days_min', type: 'integer'),
                    new OA\Property(property: 'estimated_days_max', type: 'integer'),
                    new OA\Property(property: 'active', type: 'boolean', nullable: true),
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
    public function op155_admin_delivery_zones__zone__methods__method_(): void {}

    #[OA\Post(
        path: '/admin/pickup-locations',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Store Pickup - Pickup Locations',
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
    public function op156_admin_pickup_locations(): void {}

    #[OA\Patch(
        path: '/admin/pickup-locations/{pickup}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Update Pickup - Pickup Locations',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'pickup', in: 'path', required: true, description: 'Pickup identifier', schema: new OA\Schema(type: 'string')),
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
    public function op157_admin_pickup_locations__pickup_(): void {}

    #[OA\Get(
        path: '/admin/promotions',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Promotions - Promotions',
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
    public function op158_admin_promotions(): void {}

    #[OA\Get(
        path: '/admin/promotions/{promotion}',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Show Promotion - Promotions',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'promotion', in: 'path', required: true, description: 'Promotion identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op159_admin_promotions__promotion_(): void {}

    #[OA\Get(
        path: '/admin/promotions/{promotion}/redemptions',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Promotion Redemptions - Redemptions',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'promotion', in: 'path', required: true, description: 'Promotion identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op160_admin_promotions__promotion__redemptions(): void {}

    #[OA\Post(
        path: '/admin/promotions',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Store Promotion - Promotions',
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
    public function op161_admin_promotions(): void {}

    #[OA\Patch(
        path: '/admin/promotions/{promotion}',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Update Promotion - Promotions',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'promotion', in: 'path', required: true, description: 'Promotion identifier', schema: new OA\Schema(type: 'string')),
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
    public function op162_admin_promotions__promotion_(): void {}

    #[OA\Delete(
        path: '/admin/promotions/{promotion}',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Destroy Promotion - Promotions',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'promotion', in: 'path', required: true, description: 'Promotion identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op163_admin_promotions__promotion_(): void {}

    #[OA\Get(
        path: '/admin/delivery-zones/{zone}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Get Delivery Zone',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'zone', in: 'path', required: true, description: 'Zone identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op164_admin_delivery_zones_show(): void {}

    #[OA\Delete(
        path: '/admin/delivery-zones/{zone}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Delete Delivery Zone',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'zone', in: 'path', required: true, description: 'Zone identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op165_admin_delivery_zones_destroy(): void {}

    #[OA\Get(
        path: '/admin/delivery-methods/{method}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Get Delivery Method',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'method', in: 'path', required: true, description: 'Method identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op166_admin_delivery_methods_show(): void {}

    #[OA\Delete(
        path: '/admin/delivery-methods/{method}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Delete Delivery Method',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'method', in: 'path', required: true, description: 'Method identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'In use', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op167_admin_delivery_methods_destroy(): void {}

    #[OA\Delete(
        path: '/admin/delivery-zones/{zone}/methods/{method}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Detach Method from Zone',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'zone', in: 'path', required: true, description: 'Zone identifier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'method', in: 'path', required: true, description: 'Method identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op168_admin_delivery_zones_detach_method(): void {}

    #[OA\Get(
        path: '/admin/pickup-locations/{pickup}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Get Pickup Location',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'pickup', in: 'path', required: true, description: 'Pickup identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op169_admin_pickup_locations_show(): void {}

    #[OA\Delete(
        path: '/admin/pickup-locations/{pickup}',
        tags: ['Admin - Delivery & Shipping'],
        summary: 'Delete Pickup Location',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'pickup', in: 'path', required: true, description: 'Pickup identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op170_admin_pickup_locations_destroy(): void {}

    #[OA\Post(
        path: '/admin/categories',
        tags: ['Admin - Products & Catalog'],
        summary: 'Create Category',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'parent_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'icon', type: 'string', nullable: true),
                    new OA\Property(property: 'sort_order', type: 'integer', nullable: true),
                    new OA\Property(property: 'active', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op171_admin_categories_store(): void {}

    #[OA\Get(
        path: '/admin/categories/{category}',
        tags: ['Admin - Products & Catalog'],
        summary: 'Get Category Details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, description: 'Category identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op172_admin_categories_show(): void {}

    #[OA\Patch(
        path: '/admin/categories/{category}',
        tags: ['Admin - Products & Catalog'],
        summary: 'Update Category',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, description: 'Category identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op173_admin_categories_update(): void {}

    #[OA\Delete(
        path: '/admin/categories/{category}',
        tags: ['Admin - Products & Catalog'],
        summary: 'Delete Category',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, description: 'Category identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Conflict', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op174_admin_categories_destroy(): void {}

    #[OA\Get(
        path: '/admin/reviews',
        tags: ['Admin - Reviews & Moderation'],
        summary: 'List Reviews',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected', 'flagged'])),
            new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'rating', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op175_admin_reviews_index(): void {}

    #[OA\Get(
        path: '/admin/reviews/{review}',
        tags: ['Admin - Reviews & Moderation'],
        summary: 'Get Review Details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'review', in: 'path', required: true, description: 'Review identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op176_admin_reviews_show(): void {}

    #[OA\Delete(
        path: '/admin/reviews/{review}',
        tags: ['Admin - Reviews & Moderation'],
        summary: 'Delete Review',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'review', in: 'path', required: true, description: 'Review identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op177_admin_reviews_destroy(): void {}

    #[OA\Get(
        path: '/admin/support/tickets/{supportTicket}',
        tags: ['Admin - Support & Tickets'],
        summary: 'Get Support Ticket Details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'supportTicket', in: 'path', required: true, description: 'Ticket identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op178_admin_support_tickets_show(): void {}

    #[OA\Post(
        path: '/admin/support/tickets/{supportTicket}/messages',
        tags: ['Admin - Support & Tickets'],
        summary: 'Staff Reply to Ticket',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'supportTicket', in: 'path', required: true, description: 'Ticket identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op179_admin_support_tickets_reply(): void {}

    #[OA\Get(
        path: '/admin/jobs/{job}',
        tags: ['Admin - Content & CMS'],
        summary: 'Get Career Job Posting',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, description: 'Job identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op180_admin_jobs_show(): void {}

    #[OA\Delete(
        path: '/admin/jobs/{job}',
        tags: ['Admin - Content & CMS'],
        summary: 'Delete Career Job Posting',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, description: 'Job identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op181_admin_jobs_destroy(): void {}

    #[OA\Get(
        path: '/admin/newsletter/subscribers',
        tags: ['Admin - Content & CMS'],
        summary: 'List Newsletter Subscribers',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['subscribed', 'unsubscribed'])),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op182_admin_newsletter_index(): void {}

    #[OA\Delete(
        path: '/admin/newsletter/subscribers/{subscription}',
        tags: ['Admin - Content & CMS'],
        summary: 'Delete Newsletter Subscription',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'subscription', in: 'path', required: true, description: 'Subscription identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op183_admin_newsletter_destroy(): void {}

    #[OA\Get(
        path: '/admin/reward-campaigns/{campaign}',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Get Reward Campaign Details',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'campaign', in: 'path', required: true, description: 'Campaign identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op184_admin_reward_campaigns_show(): void {}

    #[OA\Delete(
        path: '/admin/reward-campaigns/{campaign}',
        tags: ['Admin - Promotions & Rewards'],
        summary: 'Delete Reward Campaign',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'campaign', in: 'path', required: true, description: 'Campaign identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Has spins', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op185_admin_reward_campaigns_destroy(): void {}
}
