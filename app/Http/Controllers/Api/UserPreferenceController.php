<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\User\UpdateUserPreferenceRequest;
use App\Http\Resources\UserPreferenceResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class UserPreferenceController extends BaseController
{
    #[OA\Get(
        path: '/user-preferences',
        tags: ['User Preferences'],
        summary: 'Get the authenticated user preferences',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'User preferences', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');
        $preference = $user->preference()->firstOrNew();

        return $this->success(new UserPreferenceResource($preference));
    }

    #[OA\Put(
        path: '/user-preferences',
        tags: ['User Preferences'],
        summary: 'Update the authenticated user preferences',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'theme', type: 'string', enum: ['light', 'dark', 'system']),
                    new OA\Property(property: 'locale', type: 'string', example: 'en-NG'),
                    new OA\Property(property: 'currency', type: 'string', example: 'NGN'),
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
            new OA\Response(response: 200, description: 'Updated user preferences', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(UpdateUserPreferenceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');
        $preference = $user->preference()->updateOrCreate([], $request->validated());

        return $this->success(new UserPreferenceResource($preference), 'Preferences updated.');
    }

    public function deprecated(): JsonResponse
    {
        return $this->error(
            'Use GET or PUT /api/v1/user-preferences for the authenticated account.',
            405,
        );
    }
}
