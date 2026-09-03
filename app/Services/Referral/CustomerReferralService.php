<?php

namespace App\Services\Referral;

use App\Models\Affiliate\Affiliate;
use App\Models\Referral\CustomerReferral;
use App\Models\Referral\CustomerReferralAttribution;
use App\Models\Referral\CustomerReferralCode;
use App\Models\User;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerReferralService
{
    public function __construct(private readonly BusinessSettingsService $settings) {}

    public function codeFor(User $user): CustomerReferralCode
    {
        return DB::transaction(function () use ($user): CustomerReferralCode {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            return CustomerReferralCode::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['code' => 'LIM-'.Str::upper(Str::random(10)), 'active' => true],
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    public function resolve(string $code, string $sessionId, Request $request): array
    {
        $target = $this->resolveTarget($code);
        $sessionHash = $this->hash($sessionId);
        $expiresAt = now()->addDays((int) $this->settings->value('referrals.attribution_days'));

        $attribution = DB::transaction(function () use ($target, $sessionHash, $expiresAt, $request) {
            $attribution = CustomerReferralAttribution::query()->where('session_hash', $sessionHash)->lockForUpdate()->first();
            if ($attribution?->converted_at) {
                throw ValidationException::withMessages(['session_id' => ['This referral session has already been used.']]);
            }

            $attribution ??= new CustomerReferralAttribution([
                'public_id' => (string) Str::uuid(),
                'session_hash' => $sessionHash,
            ]);
            $attribution->fill([
                'type' => $target['type'],
                'code' => $target['code'],
                'customer_referral_code_id' => $target['customer_code_id'],
                'affiliate_id' => $target['affiliate_id'],
                'ip_hash' => $request->ip() ? $this->hash($request->ip()) : null,
                'user_agent_hash' => $request->userAgent() ? $this->hash($request->userAgent()) : null,
                'expires_at' => $expiresAt,
            ])->save();

            return $attribution;
        }, 3);

        return [
            'token' => $attribution->public_id,
            'type' => $attribution->type,
            'code' => $attribution->code,
            'expires_at' => $attribution->expires_at,
            'cookie' => [
                'name' => 'limitra_referral',
                'max_age' => now()->diffInSeconds($attribution->expires_at),
                'same_site' => 'lax',
                'secure' => true,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function signupAttribution(?string $code, ?string $token, string $email): ?array
    {
        if ($code && $token) {
            throw ValidationException::withMessages(['referral_code' => ['Use either a referral code or referral token, not both.']]);
        }
        if (! $code && ! $token) {
            return null;
        }

        if ($token) {
            $attribution = CustomerReferralAttribution::query()
                ->where('public_id', $token)
                ->whereNull('converted_at')
                ->where('expires_at', '>', now())
                ->first();
            if (! $attribution) {
                throw ValidationException::withMessages(['referral_token' => ['The referral token is invalid or expired.']]);
            }

            $target = $attribution->type === 'customer'
                ? $this->customerTarget($attribution->customerCode)
                : $this->affiliateTarget($attribution->affiliate);
            $target['attribution_id'] = $attribution->id;
        } else {
            $target = $this->resolveTarget((string) $code);
            $target['attribution_id'] = null;
        }

        if ($target['type'] === 'customer') {
            $referrer = User::query()->select(['id', 'email'])->findOrFail($target['referrer_id']);
            if (mb_strtolower($referrer->email) === mb_strtolower($email)) {
                throw ValidationException::withMessages(['referral_code' => ['You cannot refer yourself.']]);
            }
        }

        return $target;
    }

    /** @param array<string, mixed>|null $attribution */
    public function convert(User $user, ?array $attribution): ?CustomerReferral
    {
        if (! $attribution) {
            return null;
        }

        $record = null;
        if ($attribution['attribution_id']) {
            $record = CustomerReferralAttribution::query()->lockForUpdate()->findOrFail($attribution['attribution_id']);
            if ($record->converted_at || $record->expires_at->isPast()) {
                throw ValidationException::withMessages(['referral_token' => ['The referral token is invalid or has already been used.']]);
            }
        }

        $referral = null;
        if ($attribution['type'] === 'customer') {
            if ($attribution['referrer_id'] === $user->id) {
                throw ValidationException::withMessages(['referral_code' => ['You cannot refer yourself.']]);
            }
            $referral = CustomerReferral::query()->create([
                'referrer_id' => $attribution['referrer_id'],
                'referred_user_id' => $user->id,
                'customer_referral_attribution_id' => $record?->id,
                'status' => 'pending',
            ]);
        }

        $record?->forceFill(['converted_user_id' => $user->id, 'converted_at' => now()])->save();

        return $referral;
    }

    /** @return array<string, mixed> */
    private function resolveTarget(string $code): array
    {
        $code = Str::upper(trim($code));
        if ($this->settings->value('referrals.enabled')) {
            $customerCode = CustomerReferralCode::query()->where('code', $code)->where('active', true)->first();
            if ($customerCode) {
                return $this->customerTarget($customerCode);
            }
        }

        $affiliate = Affiliate::query()->where('code', $code)->where('status', 'active')->first();
        if ($affiliate) {
            return $this->affiliateTarget($affiliate);
        }

        throw ValidationException::withMessages(['code' => ['The referral code is invalid or inactive.']]);
    }

    /** @return array<string, mixed> */
    private function customerTarget(?CustomerReferralCode $code): array
    {
        if (! $code?->active || ! $this->settings->value('referrals.enabled')) {
            throw ValidationException::withMessages(['code' => ['The referral code is invalid or inactive.']]);
        }

        return [
            'type' => 'customer', 'code' => $code->code, 'customer_code_id' => $code->id,
            'referrer_id' => $code->user_id, 'affiliate_id' => null, 'affiliate' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function affiliateTarget(?Affiliate $affiliate): array
    {
        if (! $affiliate?->isActive()) {
            throw ValidationException::withMessages(['code' => ['The referral code is invalid or inactive.']]);
        }

        return [
            'type' => 'affiliate', 'code' => $affiliate->code, 'customer_code_id' => null,
            'referrer_id' => null, 'affiliate_id' => $affiliate->id, 'affiliate' => $affiliate,
        ];
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
