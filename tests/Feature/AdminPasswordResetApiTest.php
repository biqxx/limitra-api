<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\SendPasswordResetOtp;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use App\Notifications\PasswordResetOtpNotification;
use App\Services\Auth\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminPasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
        Notification::fake();
        Queue::fake();
    }

    public function test_administrator_can_queue_a_generic_audited_password_reset(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'customer@example.test']);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$user->id}/password-reset", [
                'channel' => 'email',
                'reason' => 'Customer requested assistance.',
            ])
            ->assertAccepted()
            ->assertExactJson([
                'success' => true,
                'message' => 'If the account can receive email, password reset instructions will be sent.',
                'data' => null,
            ]);

        $record = DB::table('password_reset_tokens')->where('user_id', $user->id)->first();
        $this->assertNotNull($record);
        $otp = Crypt::decryptString($record->delivery_secret);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
        $this->assertNotSame($otp, $record->token);

        Queue::assertPushed(SendPasswordResetOtp::class, function (SendPasswordResetOtp $job) use ($admin, $otp, $user): bool {
            $serialized = serialize($job);
            $this->assertStringNotContainsString($otp, $serialized);
            $this->assertStringNotContainsString($user->email, $serialized);
            $this->assertSame('notifications', $job->queue);

            return $job->userId === $user->id && $job->actorId === $admin->id;
        });

        Queue::pushed(SendPasswordResetOtp::class)->first()->handle(app(PasswordResetService::class));
        Notification::assertSentTo($user, PasswordResetOtpNotification::class);
        $record = DB::table('password_reset_tokens')->where('user_id', $user->id)->first();
        $this->assertNull($record->delivery_secret);
        $this->assertNotNull($record->sent_at);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.password_reset_requested',
            'actor_id' => $admin->id,
            'subject_id' => $user->id,
            'reason' => 'Customer requested assistance.',
        ]);
    }

    public function test_customer_cannot_trigger_an_administrative_password_reset(): void
    {
        $customer = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/admin/users/{$target->id}/password-reset")
            ->assertForbidden();

        $this->assertDatabaseCount('password_reset_tokens', 0);
        Queue::assertNothingPushed();
    }

    public function test_delegated_customer_manager_cannot_reset_a_staff_or_admin_account(): void
    {
        $manager = User::factory()->staff()->create();
        $managerRole = Role::factory()->create(['name' => 'customer_manager']);
        $managerRole->permissions()->attach(
            Permission::query()->where('name', 'customers.update')->firstOrFail(),
        );
        $manager->roles()->attach($managerRole, ['is_primary' => false, 'assigned_at' => now()]);
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/admin/users/{$staff->id}/password-reset")
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/admin/users/{$admin->id}/password-reset")
            ->assertForbidden();

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_queued_reset_is_cancelled_when_the_actor_loses_authority(): void
    {
        $manager = User::factory()->staff()->create();
        $managerRole = Role::factory()->create(['name' => 'customer_manager']);
        $permission = Permission::query()->where('name', 'customers.update')->firstOrFail();
        $managerRole->permissions()->attach($permission);
        $manager->roles()->attach($managerRole, ['is_primary' => false, 'assigned_at' => now()]);
        $user = User::factory()->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/admin/users/{$user->id}/password-reset")
            ->assertAccepted();
        $job = Queue::pushed(SendPasswordResetOtp::class)->first();
        $managerRole->permissions()->detach($permission);

        $job->handle(app(PasswordResetService::class));

        $record = DB::table('password_reset_tokens')->where('user_id', $user->id)->first();
        $this->assertNull($record->delivery_secret);
        $this->assertSame('authorization_revoked', $record->failure_code);
    }

    public function test_only_email_is_an_accepted_delivery_channel(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$user->id}/password-reset", ['channel' => 'sms'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('channel');
    }
}
