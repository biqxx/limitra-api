<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\User\AuthSession;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class AuthSessionManager
{
    private const KEY_PREFIX = 'auth-session:';

    private const TOUCH_KEY_PREFIX = 'auth-session-touch:';

    public function isActive(string $sessionId, int $userId): bool
    {
        $cached = Cache::get($this->key($sessionId));

        if ($cached === false) {
            return false;
        }

        if (is_array($cached)) {
            $active = (int) ($cached['user_id'] ?? 0) === $userId
                && (int) ($cached['expires_at'] ?? 0) > now()->getTimestamp();

            if ($active) {
                $this->touchIfDue($sessionId, $userId);

                return true;
            }

            $this->markInactive($sessionId);

            return false;
        }

        $session = AuthSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            $this->markInactive($sessionId);

            return false;
        }

        $this->remember($session);
        $this->touchIfDue($sessionId, $userId);

        return true;
    }

    public function remember(AuthSession $session): void
    {
        if ($session->revoked_at || ! $session->expires_at || $session->expires_at->isPast()) {
            $this->markInactive($session->id);

            return;
        }

        $secondsUntilExpiry = max(1, (int) now()->diffInSeconds($session->expires_at, false));
        $ttl = min($this->positiveTtl(), $secondsUntilExpiry);

        Cache::put($this->key($session->id), [
            'user_id' => $session->user_id,
            'expires_at' => $session->expires_at->getTimestamp(),
        ], $ttl);
    }

    public function revoke(AuthSession $session): void
    {
        if (! $session->revoked_at) {
            $session->update(['revoked_at' => now()]);
        }

        $this->markInactive($session->id);
    }

    public function extend(string $sessionId, int $userId, int $ttlMinutes): void
    {
        $session = AuthSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->first();

        if (! $session) {
            $this->markInactive($sessionId);

            return;
        }

        $session->update([
            'last_used_at' => now(),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
        $this->remember($session);
    }

    public function revokeById(string $sessionId, int $userId): void
    {
        AuthSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->markInactive($sessionId);
    }

    public function revokeOthers(User $user, ?string $exceptSessionId): void
    {
        $this->revokeQuery(
            $user->authSessions()
                ->when($exceptSessionId, fn ($query) => $query->whereKeyNot($exceptSessionId))
                ->whereNull('revoked_at')
        );
    }

    public function revokeAll(User $user): void
    {
        $this->revokeQuery($user->authSessions()->whereNull('revoked_at'));
    }

    private function revokeQuery(HasMany $query): void
    {
        $sessionIds = (clone $query)->pluck('id');

        if ($sessionIds->isEmpty()) {
            return;
        }

        $query->update(['revoked_at' => now()]);
        $sessionIds->each(fn (string $sessionId) => $this->markInactive($sessionId));
    }

    private function touchIfDue(string $sessionId, int $userId): void
    {
        if (! Cache::add($this->touchKey($sessionId), true, $this->touchInterval())) {
            return;
        }

        AuthSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['last_used_at' => now()]);
    }

    private function markInactive(string $sessionId): void
    {
        Cache::put($this->key($sessionId), false, $this->negativeTtl());
        Cache::forget($this->touchKey($sessionId));
    }

    private function key(string $sessionId): string
    {
        return self::KEY_PREFIX.$sessionId;
    }

    private function touchKey(string $sessionId): string
    {
        return self::TOUCH_KEY_PREFIX.$sessionId;
    }

    private function positiveTtl(): int
    {
        return max(1, (int) config('auth_sessions.cache_ttl', 300));
    }

    private function negativeTtl(): int
    {
        return max(1, (int) config('auth_sessions.negative_cache_ttl', 30));
    }

    private function touchInterval(): int
    {
        return max(1, (int) config('auth_sessions.touch_interval', 300));
    }
}
