<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\UserResource;
use App\Models\Social\SocialAccount;
use App\Models\User;
use App\Models\User\Profile;
use App\Services\Auth\AuthSessionManager;
use App\Services\Auth\UserStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SocialAuthController extends BaseController
{
    private const SUPPORTED_PROVIDERS = ['google', 'apple', 'facebook'];

    public function __construct(
        private readonly AuthSessionManager $authSessions,
        private readonly UserStatusService $userStatuses,
    ) {}

    public function redirect(string $provider): JsonResponse
    {
        if (! in_array(strtolower($provider), self::SUPPORTED_PROVIDERS, true)) {
            return $this->error("Unsupported provider: {$provider}.", 422);
        }

        $provider = strtolower($provider);
        $state = Str::random(40);
        Cache::put("social_state_{$state}", $provider, now()->addMinutes(15));

        $clientId = config("services.{$provider}.client_id", "demo-client-id-{$provider}");
        $redirectUri = config("services.{$provider}.redirect", "https://limitra.test/api/v1/auth/social/{$provider}/callback");

        $authUrl = match ($provider) {
            'google' => "https://accounts.google.com/o/oauth2/v2/auth?client_id={$clientId}&redirect_uri=".urlencode($redirectUri)."&response_type=code&scope=openid%20profile%20email&state={$state}",
            'apple' => "https://appleid.apple.com/auth/authorize?client_id={$clientId}&redirect_uri=".urlencode($redirectUri)."&response_type=code&scope=name%20email&state={$state}",
            default => "https://www.facebook.com/v19.0/dialog/oauth?client_id={$clientId}&redirect_uri=".urlencode($redirectUri)."&state={$state}",
        };

        return $this->success([
            'provider' => $provider,
            'authorization_url' => $authUrl,
            'state' => $state,
        ], 'Social authorization URL generated.');
    }

    public function callback(Request $request, string $provider): JsonResponse
    {
        if (! in_array(strtolower($provider), self::SUPPORTED_PROVIDERS, true)) {
            return $this->error("Unsupported provider: {$provider}.", 422);
        }

        $provider = strtolower($provider);

        $validated = $request->validate([
            'state' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'provider_user_id' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:150'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $cachedProvider = Cache::pull("social_state_{$validated['state']}");
        if (! $cachedProvider || $cachedProvider !== $provider) {
            return $this->error('Invalid or expired social authorization state.', 422);
        }

        $providerUserId = $validated['provider_user_id'] ?? ('id_'.Str::random(16));
        $email = ! empty($validated['email']) ? strtolower(trim($validated['email'])) : "{$provider}_{$providerUserId}@social.limitra.test";

        // Check if social account is already linked
        $socialAccount = SocialAccount::query()
            ->where('platform', $provider)
            ->where('platform_sender_id', $providerUserId)
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;
        } else {
            // Find existing user by email or create new
            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                $name = $validated['name'] ?? Str::before($email, '@');
                $nameParts = preg_split('/\s+/', trim($name), 2);

                $user = User::create([
                    'username' => Str::slug($name).'_'.strtolower(Str::random(4)),
                    'email' => $email,
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'user',
                    'email_verified_at' => now(),
                ]);

                Profile::create([
                    'user_id' => $user->id,
                    'first_name' => $nameParts[0],
                    'last_name' => $nameParts[1] ?? null,
                ]);
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'platform' => $provider,
                'platform_sender_id' => $providerUserId,
                'username' => $validated['name'] ?? null,
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
