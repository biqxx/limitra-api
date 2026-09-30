<?php

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Limitra User Service API',
    version: '1.0.0',
    description: 'REST API for the Limitra e-commerce user service.',
    contact: new OA\Contact(email: 'support@limitra.com'),
)]
#[OA\Server(url: '/api/v1', description: 'API v1')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'JWT token obtained from POST /api/v1/login',
)]
#[OA\Schema(
    schema: 'SuccessResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Success'),
        new OA\Property(property: 'data', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'Error message'),
        new OA\Property(property: 'errors', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'TokenPayload',
    properties: [
        new OA\Property(property: 'access_token', type: 'string'),
        new OA\Property(property: 'token_type', type: 'string', example: 'bearer'),
        new OA\Property(property: 'expires_in', type: 'integer', description: 'Seconds until expiry'),
        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
    ]
)]
#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'username', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'role', type: 'string', enum: ['user', 'admin', 'staff', 'affiliate']),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class OpenApiInfo {}
