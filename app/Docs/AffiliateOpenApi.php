<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for Affiliate endpoints.
 */
class AffiliateOpenApi
{
    #[OA\Get(
        path: '/l/{slug}',
        tags: ['Affiliate Tracking'],
        summary: 'Resolve - L',
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, description: 'Slug identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op1_l__slug_(): void {}

    #[OA\Post(
        path: '/affiliate/applications',
        tags: ['Affiliate Program'],
        summary: 'Apply - Applications',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['promotion_channels'],
                properties: [
                    new OA\Property(property: 'promotion_channels', type: 'array', enum: ['1]'], items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'website_url', type: 'string', nullable: true),
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
    public function op2_affiliate_applications(): void {}

    #[OA\Get(
        path: '/affiliate/application',
        tags: ['Affiliate Program'],
        summary: 'Status - Application',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op3_affiliate_application(): void {}

    #[OA\Get(
        path: '/affiliate/links',
        tags: ['Affiliate Program'],
        summary: 'Links - Links',
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
    public function op4_affiliate_links(): void {}

    #[OA\Post(
        path: '/affiliate/links',
        tags: ['Affiliate Program'],
        summary: 'Store Link - Links',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'product_id', type: 'string', nullable: true),
                    new OA\Property(property: 'campaign', type: 'string', nullable: true),
                    new OA\Property(property: 'landing_page', type: 'string', nullable: true),
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
    public function op5_affiliate_links(): void {}

    #[OA\Delete(
        path: '/affiliate/links/{link}',
        tags: ['Affiliate Program'],
        summary: 'Destroy Link - Links',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'link', in: 'path', required: true, description: 'Link identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op6_affiliate_links__link_(): void {}

    #[OA\Get(
        path: '/affiliate/links/{link}/analytics',
        tags: ['Affiliate Program'],
        summary: 'Link Analytics - Analytics',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'link', in: 'path', required: true, description: 'Link identifier', schema: new OA\Schema(type: 'string')),
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
    public function op7_affiliate_links__link__analytics(): void {}

    #[OA\Get(
        path: '/affiliate/promo-codes',
        tags: ['Affiliate Program'],
        summary: 'Promo Codes - Promo Codes',
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
    public function op8_affiliate_promo_codes(): void {}

    #[OA\Post(
        path: '/affiliate/promo-codes',
        tags: ['Affiliate Program'],
        summary: 'Store Promo Code - Promo Codes',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', enum: ['4', 'max:30', 'alpha_dash]']),
                    new OA\Property(property: 'discount_percent', type: 'number', nullable: true, enum: ['1', 'max:20]']),
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
    public function op9_affiliate_promo_codes(): void {}

    #[OA\Delete(
        path: '/affiliate/promo-codes/{promoCode}',
        tags: ['Affiliate Program'],
        summary: 'Destroy Promo Code - Promo Codes',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'promoCode', in: 'path', required: true, description: 'PromoCode identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op10_affiliate_promo_codes__promoCode_(): void {}

    #[OA\Get(
        path: '/affiliate/payout-methods',
        tags: ['Affiliate Program'],
        summary: 'Payout Methods - Payout Methods',
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
    public function op11_affiliate_payout_methods(): void {}

    #[OA\Post(
        path: '/affiliate/payout-methods',
        tags: ['Affiliate Program'],
        summary: 'Store Payout Method - Payout Methods',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['bank_name', 'account_number', 'account_name'],
                properties: [
                    new OA\Property(property: 'bank_name', type: 'string'),
                    new OA\Property(property: 'bank_code', type: 'string', nullable: true),
                    new OA\Property(property: 'account_number', type: 'string', enum: ['8', 'max:50]']),
                    new OA\Property(property: 'account_name', type: 'string'),
                    new OA\Property(property: 'is_default', type: 'boolean'),
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
    public function op12_affiliate_payout_methods(): void {}

    #[OA\Post(
        path: '/affiliate/payout-methods/verify',
        tags: ['Affiliate Program'],
        summary: 'Verify Payout Method - Verify',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['bank_code', 'account_number'],
                properties: [
                    new OA\Property(property: 'bank_code', type: 'string'),
                    new OA\Property(property: 'account_number', type: 'string', enum: ['8', 'max:20]']),
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
    public function op13_affiliate_payout_methods_verify(): void {}

    #[OA\Patch(
        path: '/affiliate/payout-methods/{method}/default',
        tags: ['Affiliate Program'],
        summary: 'Set Default Payout Method - Default',
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
    public function op14_affiliate_payout_methods__method__default(): void {}

    #[OA\Delete(
        path: '/affiliate/payout-methods/{method}',
        tags: ['Affiliate Program'],
        summary: 'Destroy Payout Method - Payout Methods',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'method', in: 'path', required: true, description: 'Method identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op15_affiliate_payout_methods__method_(): void {}

    #[OA\Get(
        path: '/affiliate/withdrawals',
        tags: ['Affiliate Program'],
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
    public function op16_affiliate_withdrawals(): void {}

    #[OA\Post(
        path: '/affiliate/withdrawals',
        tags: ['Affiliate Program'],
        summary: 'Store Withdrawal - Withdrawals',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', enum: ['{$minWithdrawal}]']),
                    new OA\Property(property: 'payout_method_id', type: 'integer', nullable: true),
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
    public function op17_affiliate_withdrawals(): void {}

    #[OA\Get(
        path: '/affiliate/ledger',
        tags: ['Affiliate Program'],
        summary: 'Ledger - Ledger',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op18_affiliate_ledger(): void {}

    #[OA\Get(
        path: '/affiliate/promotable-products',
        tags: ['Affiliate Program'],
        summary: 'Promotable Products - Promotable Products',
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
    public function op19_affiliate_promotable_products(): void {}

    #[OA\Get(
        path: '/affiliate/resources',
        tags: ['Affiliate Program'],
        summary: 'Resources - Resources',
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
    public function op20_affiliate_resources(): void {}

    #[OA\Get(
        path: '/affiliate/resources/{resource}/download',
        tags: ['Affiliate Program'],
        summary: 'Download Resource - Download',
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
    public function op21_affiliate_resources__resource__download(): void {}

    #[OA\Get(
        path: '/affiliate/settings',
        tags: ['Affiliate Program'],
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
    public function op22_affiliate_settings(): void {}

    #[OA\Patch(
        path: '/affiliate/settings',
        tags: ['Affiliate Program'],
        summary: 'Update Settings - Settings',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'payout_details', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
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
    public function op23_affiliate_settings(): void {}
}
