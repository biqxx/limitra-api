<?php

namespace App\Services\Referral;

use App\Jobs\RecordReferralShareEvent;
use App\Jobs\SendReferralInvitation;
use App\Models\Referral\CustomerReferralCode;
use App\Models\Referral\CustomerReferralInvitation;
use App\Models\User;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralInvitationService
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
        private readonly CustomerReferralService $referrals,
    ) {}

    public function create(User $user, array $data): CustomerReferralInvitation
    {
        if (! $this->settings->value('referrals.enabled')) {
            throw ValidationException::withMessages(['referral' => ['Customer referrals are currently disabled.']]);
        }

        $channel = isset($data['email']) ? 'email' : 'phone';
        $target = $this->normalizeTarget($channel, $data[$channel]);
        if ($channel === 'email' && hash_equals(mb_strtolower($user->email), $target)) {
            throw ValidationException::withMessages(['email' => ['You cannot invite yourself.']]);
        }

        $targetHash = $this->hash($target);
        $resendAfter = now()->subDays((int) $this->settings->value('referrals.invitation_resend_days'));
        $existing = CustomerReferralInvitation::query()
            ->where('referrer_id', $user->id)
            ->where('target_hash', $targetHash)
            ->where('created_at', '>=', $resendAfter)
            ->latest('id')
            ->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $data, $channel, $target, $targetHash): CustomerReferralInvitation {
            $code = $this->referrals->codeFor($user);
            $dailyCount = CustomerReferralInvitation::query()
                ->where('referrer_id', $user->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();
            if ($dailyCount >= (int) $this->settings->value('referrals.invitation_daily_limit')) {
                throw ValidationException::withMessages(['invitation' => ['The daily referral invitation limit has been reached.']]);
            }

            $invitation = CustomerReferralInvitation::query()->create([
                'public_id' => (string) Str::uuid(),
                'referrer_id' => $user->id,
                'customer_referral_code_id' => $code->id,
                'channel' => $channel,
                'target_hash' => $targetHash,
                'target_ciphertext' => $target,
                'target_masked' => $this->mask($channel, $target),
                'status' => $channel === 'email' ? 'queued' : 'pending_provider',
                'message' => $data['message'] ?? null,
            ]);

            if ($channel === 'email') {
                DB::afterCommit(fn () => SendReferralInvitation::dispatch($invitation->id));
            }

            return $invitation;
        }, 3);
    }

    /** @return array{event_id: string, accepted_at: string} */
    public function recordShare(User $user, array $data, Request $request): array
    {
        $code = CustomerReferralCode::query()
            ->where('user_id', $user->id)
            ->where('code', Str::upper($data['code']))
            ->where('active', true)
            ->firstOrFail();
        $frontendHost = mb_strtolower((string) parse_url((string) config('app.frontend_url'), PHP_URL_HOST));
        $sharedHost = mb_strtolower((string) parse_url($data['url'], PHP_URL_HOST));
        if ($frontendHost === '' || $sharedHost !== $frontendHost) {
            throw ValidationException::withMessages(['url' => ['The shared URL must use the storefront domain.']]);
        }

        $eventId = (string) Str::uuid();
        $occurredAt = now()->toISOString();
        RecordReferralShareEvent::dispatch(
            $eventId,
            $user->id,
            $code->id,
            $data['channel'],
            $this->hash($data['url']),
            mb_substr((string) parse_url($data['url'], PHP_URL_PATH), 0, 500),
            $request->ip() ? $this->hash($request->ip()) : null,
            $request->userAgent() ? $this->hash($request->userAgent()) : null,
            $occurredAt,
        );

        return ['event_id' => $eventId, 'accepted_at' => $occurredAt];
    }

    private function normalizeTarget(string $channel, string $target): string
    {
        return $channel === 'email'
            ? mb_strtolower(trim($target))
            : preg_replace('/[\s()-]/', '', trim($target));
    }

    private function mask(string $channel, string $target): string
    {
        if ($channel === 'email') {
            [$local, $domain] = explode('@', $target, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        return str_repeat('*', max(0, mb_strlen($target) - 4)).mb_substr($target, -4);
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
