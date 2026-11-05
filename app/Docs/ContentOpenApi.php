<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

/**
 * OpenAPI documentation for Content endpoints.
 */
class ContentOpenApi
{
    #[OA\Get(
        path: '/home',
        tags: ['Storefront CMS'],
        summary: 'Get homepage storefront layout and modules',
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op1_home(): void {}

    #[OA\Get(
        path: '/content/{page}',
        tags: ['Storefront CMS'],
        summary: 'Get Content details',
        parameters: [
            new OA\Parameter(name: 'page', in: 'path', required: true, description: 'Page identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op2_content__page_(): void {}

    #[OA\Get(
        path: '/blog/posts',
        tags: ['Blog CMS'],
        summary: 'List published blog articles',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op3_blog_posts(): void {}

    #[OA\Get(
        path: '/blog/posts/{slug}',
        tags: ['Blog CMS'],
        summary: 'Get blog article details by slug',
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, description: 'Slug identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op4_blog_posts__slug_(): void {}

    #[OA\Get(
        path: '/videos',
        tags: ['Videos & Media'],
        summary: 'List Videos',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op5_videos(): void {}

    #[OA\Post(
        path: '/videos/events/batch',
        tags: ['Videos & Media'],
        summary: 'Events Batch - Batch',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['events'],
                properties: [
                    new OA\Property(property: 'events', type: 'array', enum: ['1', 'max:100]'], items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op6_videos_events_batch(): void {}

    #[OA\Get(
        path: '/videos/{video}',
        tags: ['Videos & Media'],
        summary: 'Get Videos details',
        parameters: [
            new OA\Parameter(name: 'video', in: 'path', required: true, description: 'Video identifier', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op7_videos__video_(): void {}

    #[OA\Post(
        path: '/videos/{video}/events',
        tags: ['Videos & Media'],
        summary: 'Event - Events',
        parameters: [
            new OA\Parameter(name: 'video', in: 'path', required: true, description: 'Video identifier', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['event_type'],
                properties: [
                    new OA\Property(property: 'event_type', type: 'string', enum: ['impression', 'play', 'complete', 'like', 'share]']),
                    new OA\Property(property: 'duration_watched', type: 'integer', nullable: true, enum: ['0]']),
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
    public function op8_videos__video__events(): void {}

    #[OA\Post(
        path: '/newsletter/subscriptions',
        tags: ['Newsletter'],
        summary: 'Subscribe - Subscriptions',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'source', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op9_newsletter_subscriptions(): void {}

    #[OA\Post(
        path: '/newsletter/unsubscribe',
        tags: ['Newsletter'],
        summary: 'Unsubscribe - Unsubscribe',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'token', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op10_newsletter_unsubscribe(): void {}

    #[OA\Get(
        path: '/newsletter/unsubscribe/verify',
        tags: ['Newsletter'],
        summary: 'Verify Verify',
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op11_newsletter_unsubscribe_verify(): void {}

    #[OA\Get(
        path: '/jobs',
        tags: ['Careers'],
        summary: 'List open career opportunities',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op12_jobs(): void {}

    #[OA\Post(
        path: '/jobs/open-applications',
        tags: ['Careers'],
        summary: 'Submit general open application',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'phone', type: 'string', nullable: true),
                        new OA\Property(property: 'cv_file', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'cv_url', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'cover_letter', type: 'string', nullable: true),
                        new OA\Property(property: 'portfolio_url', type: 'string', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op13_jobs_open_applications(): void {}

    #[OA\Get(
        path: '/jobs/{slug}',
        tags: ['Careers'],
        summary: 'Get job vacancy details',
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, description: 'Slug identifier', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op14_jobs__slug_(): void {}

    #[OA\Post(
        path: '/jobs/{job}/applications',
        tags: ['Careers'],
        summary: 'Submit job application',
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, description: 'Job identifier', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'phone', type: 'string', nullable: true),
                        new OA\Property(property: 'cv_file', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'cv_url', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'cover_letter', type: 'string', nullable: true),
                        new OA\Property(property: 'portfolio_url', type: 'string', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Operation successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function op15_jobs__job__applications(): void {}
}
