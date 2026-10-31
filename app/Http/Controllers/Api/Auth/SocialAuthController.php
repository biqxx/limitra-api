<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\UserResource;
use App\Models\Social\SocialAccount;
use App\Models\User;
use App\Models\User\Profile;
use App\Services\Auth\AuthSessionManager;
use App\Services\Auth\SocialOAuthManager;
use App\Services\Auth\UserStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class SocialAuthController extends BaseController
{
    public function __construct(
        private readonly AuthSessionManager $authSessions,
        private readonly UserStatusService $userStatuses,
        private readonly SocialOAuthManager $oauthManager,
    ) {}

    public function redirect(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));

        if (! $this->oauthManager->isSupported($provider)) {
            return $this->error("Unsupported provider: {$provider}.", 422, code: ApiErrorCode::ValidationFailed);
        }

        $state = Str::random(40);
        $nonce = Str::random(32);

        // Record authenticated user if present (explicit linking flow)
        $userId = $request->user('api')?->id;

        Cache::put("social_state_{$state}", [
            'provider' => $provider,
            'nonce' => $nonce,
            'user_id' => $userId,
            'created_at' => now()->timestamp,
        ], now()->addMinutes(15));

        $oauthProvider = $this->oauthManager->provider($provider);
        $authUrl = $oauthProvider->getAuthorizationUrl($state, $nonce);

        return $this->success([
            'provider' => $provider,
            'authorization_url' => $authUrl,
            'state' => $state,
        ], 'Social authorization URL generated.');
    }

    public function callback(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));

        if (! $this->oauthManager->isSupported($provider)) {
            return $this->error("Unsupported provider: {$provider}.", 422, code: ApiErrorCode::ValidationFailed);
        }

        $validated = $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        // Replay and expiration protection: atomic pull from cache
        $stateData = Cache::pull("social_state_{$validated['state']}");

        if (! is_array($stateData) || ($stateData['provider'] ?? '') !== $provider) {
            return $this->error('Invalid, expired, or replayed social authorization state.', 422, code: ApiErrorCode::ValidationFailed);
        }

        // Exchange authorization code directly with provider
        try {
            $profile = $this->oauthManager->provider($provider)->verifyCode(
                $validated['code'],
                $stateData,
            );
        } catch (Throwable $e) {
            return $this->error(
                $e->getMessage() ?: 'Failed to verify identity with provider.',
                422,
                code: ApiErrorCode::ValidationFailed,
            );
        }

        if (! $profile->emailVerified || empty($profile->email)) {
            return $this->error('Provider account email is not verified.', 422, code: ApiErrorCode::ValidationFailed);
        }

        // Check if this is an explicit linking flow
        $linkingUser = null;
        if (! empty($stateData['user_id'])) {
            $linkingUser = User::find($stateData['user_id']);
        } elseif ($request->user('api')) {
            $linkingUser = $request->user('api');
        }

        if ($linkingUser) {
            $existingSocial = SocialAccount::query()
                ->where('platform', $provider)
                ->where('platform_sender_id', $profile->id)
                ->first();

            if ($existingSocial && $existingSocial->user_id !== $linkingUser->id) {
                return $this->error(
                    'This social account is already linked to another Limitra user.',
                    409,
                    code: ApiErrorCode::Conflict,
                );
            }

            SocialAccount::updateOrCreate(
                [
                    'user_id' => $linkingUser->id,
                    'platform' => $provider,
                ],
                [
                    'platform_sender_id' => $profile->id,
                    'username' => $profile->name,
                    'metadata' => [
                        'email' => $profile->email,
                        'linked_at' => now()->toISOString(),
                    ],
                ],
            );

            return $this->success(
                new UserResource($linkingUser->load('profile')),
                'Social account linked successfully.',
            );
        }

        // Normal login flow
        $socialAccount = SocialAccount::query()
            ->where('platform', $provider)
            ->where('platform_sender_id', $profile->id)
            ->first();

        if ($socialAccount && $socialAccount->user) {
            $user = $socialAccount->user;
        } else {
            // Prevent account takeover: existing email requires explicit authenticated linking
            $existingUser = User::query()->where('email', $profile->email)->first();

            if ($existingUser) {
                return $this->error(
                    'An account with this email address already exists. Please log in with your credentials and link your social account in settings.',
                    409,
                    code: ApiErrorCode::Conflict,
                );
            }

            $name = $profile->name ?: Str::before($profile->email, '@');
            $nameParts = preg_split('/\s+/', trim($name), 2);

            $user = User::create([
                'username' => Str::slug($name).'_'.strtolower(Str::random(4)),
                'email' => $profile->email,
                'password' => Hash::make(Str::random(32)),
                'role' => 'user',
                'email_verified_at' => now(),
            ]);

            Profile::create([
                'user_id' => $user->id,
                'first_name' => $nameParts[0],
                'last_name' => $nameParts[1] ?? null,
            ]);

            SocialAccount::create([
                'user_id' => $user->id,
                'platform' => $provider,
                'platform_sender_id' => $profile->id,
                'username' => $profile->name,
                'metadata' => [
                    'email' => $profile->email,
                    'created_via' => 'social_auth',
                ],
            ]);
        }

        if ($this->userStatuses->isSuspended($user)) {
            return $this->error(
                'This account is suspended.',
                403,
                code: ApiErrorCode::AccountSuspended,
            );
        }

        $session = $user->authSessions()->create([
            'device_name' => $validated['device_name'] ?? 'Social Login',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
            'expires_at' => now()->addMinutes(auth('api')->factory()->getTTL()),
        ]);

        $this->authSessions->remember($session);
        $jwtToken = auth('api')->claims(['sid' => $session->id])->login($user);

        return $this->success([
            'token' => $jwtToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => new UserResource($user->load('profile')),
        ], 'Social authentication successful.');
    }
}
