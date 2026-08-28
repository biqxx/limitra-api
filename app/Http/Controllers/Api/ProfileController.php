<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ProfileResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class ProfileController extends BaseController
{
    #[OA\Get(
        path: '/profile',
        tags: ['Profile'],
        summary: 'Get own profile',
        security: [['bearerAuth' => []]],
        description: 'Returns the authenticated user\'s profile.',
        responses: [
            new OA\Response(response: 200, description: 'Profile data', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(): JsonResponse
    {
        $profile = auth('api')->user()->profile;

        return $this->success(new ProfileResource($profile));
    }

    #[OA\Patch(
        path: '/profile',
        tags: ['Profile'],
        summary: 'Update own profile',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'middle_name', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'last_name', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'avatar', type: 'string', maxLength: 500, nullable: true),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 20, nullable: true),
                    new OA\Property(property: 'birthday', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'subscribe_to_newsletter', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Profile updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'username' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('users')->ignore($user)],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user), Rule::unique('users', 'pending_email')->ignore($user)],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'in:female,male,non_binary,prefer_not_to_say'],
            'subscribe_to_newsletter' => ['sometimes', 'boolean'],
        ]);

        $otp = null;
        DB::transaction(function () use ($user, &$data, &$otp): void {
            if (isset($data['name'])) {
                $parts = preg_split('/\s+/', trim($data['name']), 2);
                $data['first_name'] = $parts[0];
                $data['last_name'] = $parts[1] ?? null;
            }

            if (isset($data['username'])) {
                $user->update(['username' => $data['username']]);
            }

            if (isset($data['email']) && strtolower($data['email']) !== strtolower($user->email)) {
                $otp = (string) random_int(100_000, 999_999);
                $user->update([
                    'pending_email' => strtolower($data['email']),
                    'email_change_otp' => Hash::make($otp),
                    'email_change_expires_at' => now()->addMinutes(10),
                    'email_change_attempts' => 0,
                ]);
            }

            unset($data['name'], $data['username'], $data['email']);
            if (array_key_exists('date_of_birth', $data)) {
                $data['birthday'] = $data['date_of_birth'];
                unset($data['date_of_birth']);
            }
            $user->profile->update($data);
        });

        if ($otp) {
            Notification::route('mail', $user->pending_email)->notify(new VerifyEmailNotification($otp));
        }

        return $this->success(new UserResource($user->fresh()->load('profile')), $otp ? 'Profile updated. Verify the new email address.' : 'Profile updated.');
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $data = $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $profile = auth('api')->user()->profile;
        $oldPath = $profile->avatar;
        $path = $data['avatar']->store('avatars/'.auth('api')->id(), 'public');
        $profile->update(['avatar' => $path]);
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $this->success(new ProfileResource($profile->fresh()), 'Avatar updated.');
    }

    public function deleteAvatar(): JsonResponse
    {
        $profile = auth('api')->user()->profile;
        if ($profile->avatar) {
            Storage::disk('public')->delete($profile->avatar);
            $profile->update(['avatar' => null]);
        }

        return $this->success(new ProfileResource($profile->fresh()), 'Avatar removed.');
    }

    public function verifyEmailChange(Request $request): JsonResponse
    {
        $data = $request->validate(['otp' => ['required', 'digits:6']]);
        /** @var User $user */
        $user = auth('api')->user();

        if (! $user->pending_email || ! $user->email_change_otp || ! $user->email_change_expires_at || now()->isAfter($user->email_change_expires_at)) {
            return $this->error('Invalid or expired verification code.', 422);
        }
        if ($user->email_change_attempts >= 5) {
            return $this->error('Too many invalid attempts.', 429);
        }
        if (! Hash::check($data['otp'], $user->email_change_otp)) {
            $user->increment('email_change_attempts');

            return $this->error('Invalid or expired verification code.', 422);
        }

        $user->update([
            'email' => $user->pending_email,
            'pending_email' => null,
            'email_change_otp' => null,
            'email_change_expires_at' => null,
            'email_change_attempts' => 0,
            'email_verified_at' => now(),
        ]);

        return $this->success(new UserResource($user->fresh()->load('profile')), 'Email address updated.');
    }
}
