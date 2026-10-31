<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\User\Profile;
use App\Notifications\LoginOtpNotification;
use App\Services\Auth\AuthSessionManager;
use App\Services\Auth\UserStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));
        $cooldownSeconds = (int) config('auth.passwordless.cooldown_seconds', 60);
        $ttlMinutes = (int) config('auth.passwordless.ttl_minutes', 10);
        $cooldownKey = "otp_cooldown_{$email}";
        $cacheKey = "otp_login_{$email}";

        // Resend cooldown protection
        if (Cache::has($cooldownKey)) {
            $maskedEmail = Str::mask($email, '*', 2, strpos($email, '@') - 2);
            Log::warning('Passwordless OTP request rejected due to active cooldown', [
                'email' => $maskedEmail,
                'ip' => $request->ip(),
            ]);

            return $this->error(
                'Please wait before requesting another code.',
                429,
                code: ApiErrorCode::TooManyRequests,
            );
        }

        $code = (string) random_int(100_000, 999_999);
        $expiresAt = now()->addMinutes($ttlMinutes);

        $user = User::query()->where('email', $email)->first();

        // Audit without storing the plaintext OTP
        $maskedEmail = Str::mask($email, '*', 2, max(1, (int) strpos($email, '@') - 2));
        Log::info('Passwordless OTP requested', [
            'email' => $maskedEmail,
            'ip' => $request->ip(),
            'user_exists' => (bool) $user,
        ]);

        // Always store code if signup is allowed OR user exists
        $allowSignup = (bool) config('auth.passwordless.allow_signup', false);
        if ($user || $allowSignup) {
            Cache::put($cacheKey, [
                'hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => $expiresAt->timestamp,
            ], $expiresAt);

            Cache::put($cooldownKey, true, now()->addSeconds($cooldownSeconds));

            if ($user && ! $this->userStatuses->isSuspended($user)) {
                $user->notify(new LoginOtpNotification($code, $ttlMinutes));
            } elseif ($allowSignup && ! $user) {
                // Anonymous recipient notification
                (new User(['email' => $email]))->notify(new LoginOtpNotification($code, $ttlMinutes));
            }
        }

        // Consistent response preventing account enumeration
        return $this->success([
            'delivery' => 'email',
            'expires_in_minutes' => $ttlMinutes,
        ], 'If an account matches this email, a verification code has been sent.');
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $email = strtolower(trim($validated['email']));
        $code = $validated['code'];
        $cacheKey = "otp_login_{$email}";
        $maxAttempts = (int) config('auth.passwordless.max_attempts', 5);

        $cached = Cache::get($cacheKey);

        if (! is_array($cached)) {
            return $this->error('The verification code has expired or does not exist.', 422, code: ApiErrorCode::ValidationFailed);
        }

        $remainingSeconds = ($cached['expires_at'] ?? 0) - now()->timestamp;
        if ($remainingSeconds <= 0) {
            Cache::forget($cacheKey);

            return $this->error('The verification code has expired.', 422, code: ApiErrorCode::ValidationFailed);
        }

        $attempts = ($cached['attempts'] ?? 0) + 1;

        if ($attempts > $maxAttempts) {
            Cache::forget($cacheKey);

            return $this->error('Too many invalid attempts. Please request a new code.', 429, code: ApiErrorCode::TooManyRequests);
        }

        if (! Hash::check($code, $cached['hash'])) {
            $cached['attempts'] = $attempts;

            // Crucial: preserve fixed expiration - never extend TTL after failed attempt
            Cache::put($cacheKey, $cached, max(1, $remainingSeconds));

            $maskedEmail = Str::mask($email, '*', 2, max(1, (int) strpos($email, '@') - 2));
            Log::warning('Passwordless OTP verification failed', [
                'email' => $maskedEmail,
                'ip' => $request->ip(),
                'attempts' => $attempts,
            ]);

            return $this->error('Invalid verification code.', 422, code: ApiErrorCode::ValidationFailed);
        }

        // Successfully verified - single use
        Cache::forget($cacheKey);

        $user = User::query()->where('email', $email)->first();
        $allowSignup = (bool) config('auth.passwordless.allow_signup', false);

        if (! $user) {
            if (! $allowSignup) {
                return $this->error('No account exists with this email address. Please sign up first.', 422, code: ApiErrorCode::ResourceNotFound);
            }

            $name = Str::before($email, '@');
            $user = User::create([
                'username' => Str::slug($name).'_'.strtolower(Str::random(4)),
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
                'role' => 'user',
                'email_verified_at' => now(),
            ]);

            Profile::create([
                'user_id' => $user->id,
                'first_name' => ucfirst($name),
            ]);
        } elseif (! $user->email_verified_at) {
            $user->update(['email_verified_at' => now()]);
        }

        if ($this->userStatuses->isSuspended($user)) {
            return $this->error(
                'This account is suspended.',
                403,
                code: ApiErrorCode::AccountSuspended,
            );
        }

        $maskedEmail = Str::mask($email, '*', 2, max(1, (int) strpos($email, '@') - 2));
        Log::info('Passwordless OTP verified successfully', [
            'email' => $maskedEmail,
            'ip' => $request->ip(),
            'user_id' => $user->id,
        ]);

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
