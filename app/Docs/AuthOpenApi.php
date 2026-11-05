<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for Auth endpoints.
 */
class AuthOpenApi
{
    #[OA\Post(
        path: '/resend-verification',
        tags: ['Authentication'],
        summary: 'Resend email verification code',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op1_resend_verification(): void {}

    #[OA\Post(
        path: '/auth/otp/request',
        tags: ['Authentication'],
        summary: 'Request passwordless login OTP',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op2_auth_otp_request(): void {}

    #[OA\Post(
        path: '/auth/otp/verify',
        tags: ['Authentication'],
        summary: 'Verify passwordless login OTP',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'code'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'device_name', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op3_auth_otp_verify(): void {}

    #[OA\Get(
        path: '/auth/social/{provider}/redirect',
        tags: ['Authentication'],
        summary: 'Redirect to social identity provider for OAuth',
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, description: 'Provider identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op4_auth_social__provider__redirect(): void {}

    #[OA\Post(
        path: '/auth/social/{provider}/callback',
        tags: ['Authentication'],
        summary: 'Handle social identity provider OAuth callback',
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, description: 'Provider identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['state', 'code'],
                properties: [
                    new OA\Property(property: 'state', type: 'string'),
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'device_name', type: 'string', nullable: true),
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
    public function op5_auth_social__provider__callback(): void {}

    #[OA\Post(
        path: '/profile/avatar',
        tags: ['Customer Account & Profile'],
        summary: 'Upload profile avatar image',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'avatar', type: 'string', format: 'binary'),
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
    public function op6_profile_avatar(): void {}

    #[OA\Delete(
        path: '/profile/avatar',
        tags: ['Customer Account & Profile'],
        summary: 'Delete current profile avatar',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op7_profile_avatar(): void {}

    #[OA\Post(
        path: '/profile/email/verify',
        tags: ['Customer Account & Profile'],
        summary: 'Verify email change request',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['otp'],
                properties: [
                    new OA\Property(property: 'otp', type: 'integer'),
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
    public function op8_profile_email_verify(): void {}

    #[OA\Get(
        path: '/account/dashboard',
        tags: ['Customer Account & Profile'],
        summary: 'Get customer account summary dashboard',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op9_account_dashboard(): void {}

    #[OA\Post(
        path: '/account/password/change',
        tags: ['Customer Account & Profile'],
        summary: 'Change account password',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['current_password', 'password'],
                properties: [
                    new OA\Property(property: 'current_password', type: 'string'),
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
    public function op10_account_password_change(): void {}

    #[OA\Get(
        path: '/account/sessions',
        tags: ['Customer Account & Profile'],
        summary: 'List active authentication sessions',
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
    public function op11_account_sessions(): void {}

    #[OA\Delete(
        path: '/account/sessions/{session}',
        tags: ['Customer Account & Profile'],
        summary: 'Revoke active session',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'session', in: 'path', required: true, description: 'Session identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op12_account_sessions__session_(): void {}

    #[OA\Get(
        path: '/account/connected-accounts',
        tags: ['Customer Account & Profile'],
        summary: 'List connected social accounts',
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
    public function op13_account_connected_accounts(): void {}

    #[OA\Delete(
        path: '/account/connected-accounts/{platform}',
        tags: ['Customer Account & Profile'],
        summary: 'Disconnect linked social account',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'platform', in: 'path', required: true, description: 'Platform identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op14_account_connected_accounts__platform_(): void {}

    #[OA\Post(
        path: '/staff/invitations/accept',
        tags: ['Staff Onboarding'],
        summary: 'Accept staff invitation and activate credentials',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['token', 'username', 'password'],
                properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'password', type: 'string', enum: ['8']),
                    new OA\Property(property: 'phone', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op15_staff_invitations_accept(): void {}

    #[OA\Get(
        path: '/privacy/consents',
        tags: ['Privacy & Compliance'],
        summary: 'List recorded privacy consents',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op16_privacy_consents(): void {}

    #[OA\Put(
        path: '/privacy/consents',
        tags: ['Privacy & Compliance'],
        summary: 'Update Consents',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['analytics', 'marketing', 'third_party_sharing'],
                properties: [
                    new OA\Property(property: 'policy_version', type: 'string'),
                    new OA\Property(property: 'analytics', type: 'boolean'),
                    new OA\Property(property: 'marketing', type: 'boolean'),
                    new OA\Property(property: 'third_party_sharing', type: 'boolean'),
                    new OA\Property(property: 'personalized_ads', type: 'boolean'),
                    new OA\Property(property: 'source', type: 'string'),
                    new OA\Property(property: 'guest_token', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op17_privacy_consents(): void {}

    #[OA\Post(
        path: '/age-verification',
        tags: ['Privacy & Compliance'],
        summary: 'Verify Age Verification',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['birth_date', 'confirmed'],
                properties: [
                    new OA\Property(property: 'birth_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'confirmed', type: 'boolean', nullable: true),
                    new OA\Property(property: 'guest_token', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op18_age_verification(): void {}

    #[OA\Post(
        path: '/account/data-export',
        tags: ['Customer Account & Profile'],
        summary: 'Request complete GDPR account data export',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'Optional unique idempotency key for safe request replay protection', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['password'],
                properties: [
                    new OA\Property(property: 'password', type: 'string'),
                    new OA\Property(property: 'async', type: 'boolean'),
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
    public function op19_account_data_export(): void {}

    #[OA\Get(
        path: '/account/data-export/{export}',
        tags: ['Customer Account & Profile'],
        summary: 'Get data export generation status',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'export', in: 'path', required: true, description: 'Export identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op20_account_data_export__export_(): void {}

    #[OA\Get(
        path: '/account/data-export/{export}/download',
        tags: ['Customer Account & Profile'],
        summary: 'Download completed account data export file',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'export', in: 'path', required: true, description: 'Export identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op21_account_data_export__export__download(): void {}

    #[OA\Post(
        path: '/account/deletion-request',
        tags: ['Customer Account & Profile'],
        summary: 'Schedule account deletion request',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['password', 'confirm'],
                properties: [
                    new OA\Property(property: 'password', type: 'string'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                    new OA\Property(property: 'confirm', type: 'boolean'),
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
    public function op22_account_deletion_request(): void {}

    #[OA\Delete(
        path: '/account/deletion-request',
        tags: ['Customer Account & Profile'],
        summary: 'Cancel scheduled account deletion request',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op23_account_deletion_request(): void {}

    #[OA\Patch(
        path: '/addresses/{address}',
        tags: ['Addresses'],
        summary: 'Update delivery or billing address',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'address', in: 'path', required: true, description: 'Address identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'recipient_name', 'phone', 'line1', 'city', 'state'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['billing', 'delivery]']),
                    new OA\Property(property: 'label', type: 'string', nullable: true),
                    new OA\Property(property: 'recipient_name', type: 'string'),
                    new OA\Property(property: 'phone', type: 'string'),
                    new OA\Property(property: 'line1', type: 'string'),
                    new OA\Property(property: 'line2', type: 'string', nullable: true),
                    new OA\Property(property: 'city', type: 'string'),
                    new OA\Property(property: 'landmark', type: 'string', nullable: true),
                    new OA\Property(property: 'state', type: 'string'),
                    new OA\Property(property: 'country', type: 'string', nullable: true),
                    new OA\Property(property: 'postal_code', type: 'string', nullable: true),
                    new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
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
    public function op24_addresses__address_(): void {}

    #[OA\Patch(
        path: '/accounts/{account}',
        tags: ['Accounts'],
        summary: 'Update user account record',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, description: 'Account identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['currency'],
                properties: [
                    new OA\Property(property: 'currency', type: 'string'),
                    new OA\Property(property: 'bonus_balance', type: 'string'),
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
    public function op25_accounts__account_(): void {}

    #[OA\Patch(
        path: '/user-preferences',
        tags: ['User Preferences'],
        summary: 'Update user notification and display preferences',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'theme', type: 'string'),
                    new OA\Property(property: 'locale', type: 'string'),
                    new OA\Property(property: 'currency', type: 'string'),
                    new OA\Property(property: 'notifications_enabled', type: 'boolean'),
                    new OA\Property(property: 'email_notifications', type: 'boolean'),
                    new OA\Property(property: 'sms_notifications', type: 'boolean'),
                    new OA\Property(property: 'push_notifications', type: 'boolean'),
                    new OA\Property(property: 'in_app_notifications', type: 'boolean'),
                    new OA\Property(property: 'marketing_emails', type: 'boolean'),
                    new OA\Property(property: 'marketing_sms', type: 'boolean'),
                    new OA\Property(property: 'marketing_push', type: 'boolean'),
                    new OA\Property(property: 'marketing_in_app', type: 'boolean'),
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
    public function op26_user_preferences(): void {}
}
