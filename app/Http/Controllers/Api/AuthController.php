<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Models\Affiliate\Affiliate;
use App\Models\User;
use App\Models\User\Profile;
use App\Notifications\LoginNotification;
use App\Notifications\PasswordResetOtpNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\AffiliateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class AuthController extends BaseController
{
    // ═══════════════════════════════════════════════════════════════════════
    //  Public endpoints (no auth required)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Register a new user (role = user).
     * Sends an email verification OTP before the account is usable.
     */
    #[OA\Post(
        path: '/signup',
        tags: ['Authentication'],
        summary: 'Register a new user',
        description: 'Creates a user account with role=user and sends a six-digit email verification code.',
        parameters: [
            new OA\Parameter(name: 'ref', in: 'query', required: false, description: 'Affiliate referral code', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'John Doe'),
                    new OA\Property(property: 'username', type: 'string', maxLength: 50, nullable: true, example: 'johndoe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', minLength: 8, example: 'secret123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', minLength: 8, example: 'secret123'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+2348000000000'),
                    new OA\Property(property: 'referral_code', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Account created, verification email sent', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function signup(SignupRequest $request): JsonResponse
    {
        $otp = (string) random_int(100_000, 999_999);
        $affiliate = null;
        $referralCode = $request->validated('referral_code') ?? $request->query('ref');

        if ($referralCode) {
            $affiliate = Affiliate::where('code', $referralCode)
                ->where('status', 'active')
                ->first();
        }

        $user = DB::transaction(function () use ($request, $otp, $affiliate) {
            $nameParts = preg_split('/\s+/', trim($request->validated('name')), 2);

            $user = User::create([
                'username' => $request->validated('username') ?: $this->uniqueUsername($request->validated('name')),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
                'role' => 'user',
                'referred_by' => $affiliate?->id,
                'email_verification_otp' => Hash::make($otp),
                'email_verification_expires_at' => now()->addMinutes(10),
                'email_verification_sent_at' => now(),
            ]);

            Profile::create([
                'user_id' => $user->id,
                'first_name' => $nameParts[0],
                'last_name' => $nameParts[1] ?? null,
                'phone' => $request->validated('phone'),
            ]);

            return $user;
        });

        // Record referral outside the transaction (non-critical).
        if ($affiliate) {
            app(AffiliateService::class)->recordReferral($user->id, $affiliate);
        }

        $user->notify(new VerifyEmailNotification($otp));

        return $this->success(
            null,
            'Account created. Please check your email for the verification code.',
            201
        );
    }

    /**
     * Verify email address using the OTP sent to the user's inbox.
     */
    #[OA\Post(
        path: '/verify-email',
        tags: ['Authentication'],
        summary: 'Verify email address',
        description: "Validates the six-digit code sent to the user's inbox and activates the account. Returns a JWT on success.",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'otp'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'otp', type: 'string', example: '123456'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Email verified, JWT returned', content: new OA\JsonContent(ref: '#/components/schemas/TokenPayload')),
            new OA\Response(response: 422, description: 'Invalid or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))
            ->whereNull('email_verified_at')
            ->first();

        if (! $user || ! $user->email_verification_otp) {
            return $this->error('Invalid or expired verification code.', 422);
        }

        if ($user->email_verification_attempts >= 5) {
            return $this->error('Too many invalid attempts. Please request a new code.', 429);
        }

        if (! $user->email_verification_expires_at || now()->isAfter($user->email_verification_expires_at)) {
            $this->clearEmailVerification($user);

            return $this->error('Verification code has expired. Please request a new one.', 422);
        }

        if (! Hash::check($request->validated('otp'), $user->email_verification_otp)) {
            $user->increment('email_verification_attempts');

            return $this->error('Invalid or expired verification code.', 422);
        }

        $user->update([
            'email_verified_at' => now(),
            'email_verification_otp' => null,
            'email_verification_expires_at' => null,
            'email_verification_sent_at' => null,
            'email_verification_attempts' => 0,
        ]);

        $jwtToken = auth('api')->login($user);

        return $this->success(
            $this->tokenPayload($jwtToken, $user),
            'Email verified successfully.'
        );
    }

    public function resendVerification(ResendVerificationRequest $request): JsonResponse
    {
        $message = 'If an unverified account exists for this email, a new verification code has been sent.';
        $user = User::where('email', $request->validated('email'))
            ->whereNull('email_verified_at')
            ->first();

        if (! $user) {
            return $this->success(null, $message);
        }

        $otp = (string) random_int(100_000, 999_999);

        $user->update([
            'email_verification_otp' => Hash::make($otp),
            'email_verification_expires_at' => now()->addMinutes(10),
            'email_verification_sent_at' => now(),
            'email_verification_attempts' => 0,
        ]);

        $user->notify(new VerifyEmailNotification($otp));

        return $this->success(null, $message);
    }

    /**
     * Authenticate a verified user and return a JWT.
     */
    #[OA\Post(
        path: '/login',
        tags: ['Authentication'],
        summary: 'Login',
        description: 'Authenticates a verified user and returns a JWT access token.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', example: 'secret123'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Logged in successfully', content: new OA\JsonContent(ref: '#/components/schemas/TokenPayload')),
            new OA\Response(response: 401, description: 'Invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Email not verified', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! $jwtToken = auth('api')->attempt($credentials)) {
            return $this->error('Invalid credentials.', 401);
        }

        /** @var User $user */
        $user = auth('api')->user();

        if (! $user->email_verified_at) {
            auth('api')->logout();

            return $this->error(
                'Email address not verified. Please check your inbox.',
                403
            );
        }

        // Queued — does not delay the response.
        $user->notify(new LoginNotification(
            $request->ip() ?? 'unknown',
            $request->userAgent() ?? 'unknown'
        ));

        return $this->success(
            $this->tokenPayload($jwtToken, $user->load('profile')),
            'Logged in successfully.'
        );
    }

    /**
     * Invalidate the current JWT (add to blacklist).
     */
    #[OA\Post(
        path: '/logout',
        tags: ['Authentication'],
        summary: 'Logout',
        security: [['bearerAuth' => []]],
        description: 'Invalidates the current JWT and adds it to the blacklist.',
        responses: [
            new OA\Response(response: 200, description: 'Logged out successfully', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return $this->success(null, 'Logged out successfully.');
    }

    /**
     * Issue a fresh token using the current (still valid) one.
     */
    #[OA\Post(
        path: '/refresh',
        tags: ['Authentication'],
        summary: 'Refresh JWT',
        security: [['bearerAuth' => []]],
        description: 'Issues a new JWT using the currently valid token.',
        responses: [
            new OA\Response(response: 200, description: 'Token refreshed', content: new OA\JsonContent(ref: '#/components/schemas/TokenPayload')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function refresh(): JsonResponse
    {
        $newToken = auth('api')->refresh();

        return $this->success(
            $this->tokenPayload($newToken, auth('api')->user()),
            'Token refreshed.'
        );
    }

    /**
     * Return the authenticated user's profile.
     */
    #[OA\Get(
        path: '/me',
        tags: ['Authentication'],
        summary: 'Get current user',
        security: [['bearerAuth' => []]],
        description: 'Returns the authenticated user with their profile.',
        responses: [
            new OA\Response(response: 200, description: 'Current user', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function me(): JsonResponse
    {
        return $this->success(
            new UserResource(auth('api')->user()->load('profile'))
        );
    }

    /**
     * Generate and email a 6-digit OTP for password reset.
     */
    #[OA\Post(
        path: '/forgot-password',
        tags: ['Authentication'],
        summary: 'Request password reset OTP',
        description: "Generates a 6-digit OTP and sends it to the user's email. OTP expires in 15 minutes.",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'OTP sent', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::where('email', $email)->first();

        // Always return the same response so callers cannot discover whether
        // an email address belongs to an account.
        if (! $user) {
            return $this->success(null, 'If an account exists for this email, a password reset OTP has been sent.');
        }

        $otp = (string) random_int(100_000, 999_999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($otp),
                'expires_at' => now()->addMinutes(15),
                'created_at' => now(),
            ]
        );

        $user->notify(new PasswordResetOtpNotification($otp));

        return $this->success(null, 'If an account exists for this email, a password reset OTP has been sent.');
    }

    /**
     * Verify OTP and set a new password.
     */
    #[OA\Post(
        path: '/reset-password',
        tags: ['Authentication'],
        summary: 'Reset password with OTP',
        description: 'Verifies the OTP sent by forgot-password and sets a new password.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'otp', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'otp', type: 'string', example: '123456'),
                    new OA\Property(property: 'password', type: 'string', minLength: 8, example: 'newSecret123'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password reset successfully', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Invalid/expired OTP', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $record = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->first();

        if (! $record) {
            return $this->error('No password reset request found for this email.', 422);
        }

        if ($record->expires_at && now()->isAfter($record->expires_at)) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return $this->error('OTP has expired. Please request a new one.', 422);
        }

        if (! Hash::check($data['otp'], $record->token)) {
            return $this->error('Invalid OTP.', 422);
        }

        User::where('email', $data['email'])->update([
            'password' => Hash::make($data['password']),
        ]);

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return $this->success(null, 'Password reset successfully. You may now log in.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Private helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function tokenPayload(string $token, mixed $user): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60, // seconds
            'user' => new UserResource($user),
        ];
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->substr(0, 40)
            ->value() ?: 'user';
        $username = $base;
        $suffix = 1;

        while (User::where('username', $username)->exists()) {
            $username = Str::limit($base, 40, '').'_'.$suffix++;
        }

        return $username;
    }

    private function clearEmailVerification(User $user): void
    {
        $user->update([
            'email_verification_otp' => null,
            'email_verification_expires_at' => null,
            'email_verification_sent_at' => null,
            'email_verification_attempts' => 0,
        ]);
    }
}
