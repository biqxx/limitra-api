<?php

namespace Tests\Feature;

use App\Jobs\PruneExpiredAuthSessions;
use App\Jobs\PruneExpiredCheckoutQuotes;
use App\Jobs\PruneRawAnalytics;
use App\Models\Address\Address;
use App\Models\Cart\Cart;
use App\Models\Commerce\CheckoutQuote;
use App\Models\Commerce\DeliveryMethod;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\User\AuthSession;
use App\Services\Maintenance\MaintenancePruningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenancePruningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maintenance.auth_session_retention_days' => 30,
            'maintenance.checkout_quote_retention_hours' => 24,
            'maintenance.analytics_retention_days' => 90,
            'maintenance.prune_batch_size' => 100,
        ]);
        Cache::flush();
    }

    public function test_only_aged_expired_or_revoked_auth_sessions_are_pruned(): void
    {
        $user = $this->user();
        $oldExpired = $this->authSession($user, now()->subDays(31));
        $oldRevoked = $this->authSession($user, now()->addDay(), now()->subDays(31));
        $recentExpired = $this->authSession($user, now()->subDays(29));
        $active = $this->authSession($user, now()->addDay());

        $deleted = app(MaintenancePruningService::class)->pruneAuthSessions();

        $this->assertSame(2, $deleted);
        $this->assertDatabaseMissing('auth_sessions', ['id' => $oldExpired->id]);
        $this->assertDatabaseMissing('auth_sessions', ['id' => $oldRevoked->id]);
        $this->assertDatabaseHas('auth_sessions', ['id' => $recentExpired->id]);
        $this->assertDatabaseHas('auth_sessions', ['id' => $active->id]);
    }

    public function test_only_unused_expired_checkout_quotes_without_orders_are_pruned(): void
    {
        [$user, $cart, $address, $deliveryMethod] = $this->checkoutDependencies();
        $expired = $this->quote($user, $cart, $address, $deliveryMethod, now()->subHours(25));
        $recentlyExpired = $this->quote($user, $cart, $address, $deliveryMethod, now()->subHours(23));
        $consumed = $this->quote($user, $cart, $address, $deliveryMethod, now()->subHours(25), now()->subHours(24));
        $ordered = $this->quote($user, $cart, $address, $deliveryMethod, now()->subHours(25));
        $order = $this->order($user, $ordered, $address);
        $order->delete();

        $deleted = app(MaintenancePruningService::class)->pruneCheckoutQuotes();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('checkout_quotes', ['id' => $expired->id]);
        $this->assertDatabaseHas('checkout_quotes', ['id' => $recentlyExpired->id]);
        $this->assertDatabaseHas('checkout_quotes', ['id' => $consumed->id]);
        $this->assertDatabaseHas('checkout_quotes', ['id' => $ordered->id]);
    }

    public function test_raw_analytics_pruning_preserves_aggregates_and_recent_rows(): void
    {
        $oldEventId = DB::table('analytics_events')->insertGetId([
            'event_id' => (string) Str::uuid(),
            'session_id' => 'old-event-session',
            'event_type' => 'page_view',
            'created_at' => now()->subDays(91),
        ]);
        $recentEventId = DB::table('analytics_events')->insertGetId([
            'event_id' => (string) Str::uuid(),
            'session_id' => 'recent-event-session',
            'event_type' => 'page_view',
            'created_at' => now()->subDays(89),
        ]);

        foreach (['page_views', 'cart_events', 'order_events'] as $table) {
            $eventType = match ($table) {
                'cart_events' => 'cart_updated',
                'order_events' => 'order_placed',
                default => null,
            };
            $base = $eventType === null ? [] : ['event_type' => $eventType];

            DB::table($table)->insert($base + [
                'analytics_event_id' => $oldEventId,
                'session_id' => 'old-'.$table,
                'created_at' => now()->subDays(91),
            ]);
            DB::table($table)->insert($base + [
                'analytics_event_id' => $recentEventId,
                'session_id' => 'recent-'.$table,
                'created_at' => now()->subDays(89),
            ]);
        }

        DB::table('traffic_sources')->insert([
            ['session_id' => 'old-traffic', 'source' => 'direct', 'created_at' => now()->subDays(91)],
            ['session_id' => 'recent-traffic', 'source' => 'direct', 'created_at' => now()->subDays(89)],
        ]);
        DB::table('analytics_daily_aggregates')->insert([
            'date' => now()->subDays(91)->toDateString(),
            'visits' => 10,
            'unique_visitors' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deleted = app(MaintenancePruningService::class)->pruneRawAnalytics();

        $this->assertSame(1, $deleted['analytics_events']);
        $this->assertSame(1, $deleted['page_views']);
        $this->assertSame(1, $deleted['cart_events']);
        $this->assertSame(1, $deleted['order_events']);
        $this->assertSame(1, $deleted['traffic_sources']);
        $this->assertSame(0, $deleted['product_views']);

        foreach (['analytics_events', 'page_views', 'cart_events', 'order_events'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }

        $this->assertDatabaseHas('traffic_sources', ['session_id' => 'recent-traffic']);
        $this->assertDatabaseCount('analytics_daily_aggregates', 1);
    }

    public function test_pruning_jobs_use_the_maintenance_queue_and_execution_locks(): void
    {
        $jobs = [
            new PruneExpiredAuthSessions,
            new PruneExpiredCheckoutQuotes,
            new PruneRawAnalytics,
        ];

        foreach ($jobs as $job) {
            $this->assertSame('maintenance', $job->queue);

            $middleware = $job->middleware()[0];

            $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
            $this->assertGreaterThan(0, $middleware->releaseAfter);
            $this->assertGreaterThan(0, $middleware->expiresAfter);

            $lock = Cache::lock($middleware->getLockKey($job), 10);
            $this->assertTrue($lock->get());

            $ran = false;

            try {
                $middleware->handle($job, function () use (&$ran): void {
                    $ran = true;
                });
            } finally {
                $lock->release();
            }

            $this->assertFalse($ran);
        }
    }

    private function user(): User
    {
        return User::query()->create([
            'username' => 'maintenance-'.Str::lower(Str::random(8)),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => 'user',
        ]);
    }

    private function authSession(User $user, mixed $expiresAt, mixed $revokedAt = null): AuthSession
    {
        return AuthSession::query()->create([
            'user_id' => $user->id,
            'device_name' => 'Test device',
            'last_used_at' => now()->subHour(),
            'expires_at' => $expiresAt,
            'revoked_at' => $revokedAt,
        ]);
    }

    private function checkoutDependencies(): array
    {
        $user = $this->user();
        $cart = Cart::activeForUser($user->id);
        $address = Address::query()->create([
            'user_id' => $user->id,
            'type' => 'delivery',
            'label' => 'Home',
            'recipient_name' => 'Maintenance User',
            'phone' => '+2348000000000',
            'line1' => '1 Test Street',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'country' => 'NG',
            'is_default' => true,
        ]);
        $deliveryMethod = DeliveryMethod::query()->create([
            'code' => 'maintenance-standard',
            'name' => 'Standard',
            'type' => 'standard',
        ]);

        return [$user, $cart, $address, $deliveryMethod];
    }

    private function quote(
        User $user,
        Cart $cart,
        Address $address,
        DeliveryMethod $deliveryMethod,
        mixed $expiresAt,
        mixed $consumedAt = null,
    ): CheckoutQuote {
        return CheckoutQuote::query()->create([
            'user_id' => $user->id,
            'cart_id' => $cart->id,
            'address_id' => $address->id,
            'delivery_method_id' => $deliveryMethod->id,
            'payment_method' => 'card',
            'currency' => 'NGN',
            'subtotal' => 1000,
            'discount_total' => 0,
            'shipping_total' => 0,
            'wallet_credit' => 0,
            'grand_total' => 1000,
            'address_snapshot' => [],
            'shipping_snapshot' => [],
            'expires_at' => $expiresAt,
            'consumed_at' => $consumedAt,
        ]);
    }

    private function order(User $user, CheckoutQuote $quote, Address $address): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'checkout_quote_id' => $quote->id,
            'number' => 'ORD-'.Str::upper(Str::random(12)),
            'currency' => 'NGN',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'total_amount' => 1000,
            'payment_method' => 'card',
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address_id' => $address->id,
            'shipping_address' => [],
        ]);
    }
}
