<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\User\AuthSession;
use App\Services\Auth\AuthSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthSessionCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'auth_sessions.cache_ttl' => 300,
            'auth_sessions.negative_cache_ttl' => 30,
            'auth_sessions.touch_interval' => 300,
        ]);
    }

    public function test_active_session_lookup_is_served_from_cache_after_the_first_read(): void
    {
        $session = $this->authSession();
        $manager = app(AuthSessionManager::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($manager->isActive($session->id, $session->user_id));
        $this->assertTrue($manager->isActive($session->id, $session->user_id));

        $sessionSelects = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_starts_with(mb_strtolower($query['query']), 'select')
                && str_contains(mb_strtolower($query['query']), 'auth_sessions')
        );

        $this->assertCount(1, $sessionSelects);
    }

    public function test_revoking_a_session_immediately_replaces_its_cached_active_state(): void
    {
        $session = $this->authSession();
        $manager = app(AuthSessionManager::class);
        $manager->remember($session);

        $this->assertTrue($manager->isActive($session->id, $session->user_id));

        $manager->revoke($session);

        $this->assertFalse($manager->isActive($session->id, $session->user_id));
        $this->assertFalse(Cache::get('auth-session:'.$session->id));
        $this->assertNotNull($session->fresh()->revoked_at);
    }

    public function test_password_change_can_revoke_and_invalidate_every_other_session(): void
    {
        $current = $this->authSession();
        $other = AuthSession::create([
            'user_id' => $current->user_id,
            'device_name' => 'Other device',
            'last_used_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $manager = app(AuthSessionManager::class);
        $manager->remember($current);
        $manager->remember($other);

        $manager->revokeOthers($current->user, $current->id);

        $this->assertTrue($manager->isActive($current->id, $current->user_id));
        $this->assertFalse($manager->isActive($other->id, $other->user_id));
        $this->assertNull($current->fresh()->revoked_at);
        $this->assertNotNull($other->fresh()->revoked_at);
    }

    public function test_refreshing_a_token_extends_the_database_and_cached_session_expiry(): void
    {
        $session = $this->authSession();
        $manager = app(AuthSessionManager::class);
        $originalExpiry = $session->expires_at;

        $manager->extend($session->id, $session->user_id, 120);

        $session->refresh();
        $this->assertTrue($session->expires_at->greaterThan($originalExpiry));
        $this->assertTrue($manager->isActive($session->id, $session->user_id));
        $this->assertSame(
            $session->expires_at->getTimestamp(),
            Cache::get('auth-session:'.$session->id)['expires_at'],
        );
    }

    private function authSession(): AuthSession
    {
        $user = User::create([
            'username' => 'session-user-'.fake()->unique()->numerify('#####'),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        return AuthSession::create([
            'user_id' => $user->id,
            'device_name' => 'Test device',
            'last_used_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ]);
    }
}
