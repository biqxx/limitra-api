<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\User\Profile;
use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\AuthSessionManager;
use App\Services\Auth\UserStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordlessOtpController extends BaseController
{
    public function __construct(
        private readonly AuthSessionManager $authSessions,
        private readonly UserStatusService $userStatuses,
    ) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));
        $code = (string) random_int(100_000, 999_999);

        $user = User::query()->where('email', $email)->first();

        if ($user && ! $this->userStatuses->isSuspended($user)) {
            Cache::put("otp_login_{$email}", [
                'hash' => Hash::make($code),
                'attempts' => 0,
            ], now()->addMinutes(10));

            $user->notify(new VerifyEmailNotification($code));
        }

        return $this->success([
            'delivery' => 'email',
            'expires_in_minutes' => 10,
        ], 'If an account matches this email, a verification code has been sent.');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $email = strtolower(trim($validated['email']));
        $code = $validated['code'];
        $cacheKey = "otp_login_{$email}";

        $cached = Cache::get($cacheKey);

        if (! $cached) {
            return $this->error('The verification code has expired or does not exist.', 422);
        }

        if (($cached['attempts'] ?? 0) >= 5) {
            Cache::forget($cacheKey);

            return $this->error('Too many invalid attempts. Please request a new code.', 429);
        }

        if (! Hash::check($code, $cached['hash'])) {
            $cached['attempts'] = ($cached['attempts'] ?? 0) + 1;
            Cache::put($cacheKey, $cached, now()->addMinutes(10));

            return $this->error('Invalid verification code.', 422);
        }

        Cache::forget($cacheKey);

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $name = Str::before($email, '@');
            $user = User::create([
                'username' => $name.'_'.strtolower(Str::random(4)),
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
                'role' => 'user',
                'email_verified_at' => now(),
            ]);

            Profile::create([
                'user_id' => $user->id,
                'first_name' => ucfirst($name),
            ]);
        } else {
            if (! $user->email_verified_at) {
                $user->update(['email_verified_at' => now()]);
            }
        }

        if ($this->userStatuses->isSuspended($user)) {
            return $this->error(
                'This account is suspended.',
                403,
                code: ApiErrorCode::AccountSuspended,
            );
        }

        $session = $user->authSessions()->create([
            'device_name' => $validated['device_name'] ?? 'Web Browser',
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
        ], 'Logged in successfully.');
    }
}
