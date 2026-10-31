<?php

namespace Tests\Feature\Privacy;

use App\Enums\UserStatus;
use App\Http\Middleware\TrackAnalytics;
use App\Jobs\Privacy\ProcessAccountDataExportJob;
use App\Models\Address\Address;
use App\Models\Order\Order;
use App\Models\Privacy\AccountDataExport;
use App\Models\User;
use App\Models\User\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivacyAndDataRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
        Storage::fake('local');
    }

    public function test_user_can_request_asynchronous_data_export_and_job_is_dispatched(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'password' => 'secret12345',
            'role' => 'user',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/account/data-export', [
                'password' => 'secret12345',
                'async' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $exportId = $response->json('data.export_id');
        $this->assertNotEmpty($exportId);

        Queue::assertPushed(ProcessAccountDataExportJob::class, function (ProcessAccountDataExportJob $job) use ($exportId): bool {
            return $job->exportId === $exportId;
        });

        $this->assertDatabaseHas('audit_events', [
            'action' => 'account.data_export_requested',
            'actor_id' => $user->id,
        ]);
    }

    public function test_process_account_data_export_job_generates_private_file_and_completes_export(): void
    {
        $user = User::factory()->create([
            'username' => 'export_job_user',
            'email' => 'job_export@example.test',
            'role' => 'user',
            'status' => 'active',
        ]);
        Profile::create([
            'user_id' => $user->id,
            'first_name' => 'Sara',
            'last_name' => 'Connor',
        ]);

        $export = AccountDataExport::create([
            'user_id' => $user->id,
            'status' => AccountDataExport::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $job = new ProcessAccountDataExportJob($export->id);
        $job->handle();

        $export->refresh();
        $this->assertSame(AccountDataExport::STATUS_COMPLETED, $export->status);
        $this->assertNotNull($export->completed_at);
        $this->assertNotNull($export->file_path);
        Storage::disk('local')->assertExists($export->file_path);

        $jsonContent = Storage::disk('local')->get($export->file_path);
        $decoded = json_decode($jsonContent, true);
        $this->assertSame('job_export@example.test', $decoded['user']['email']);
        $this->assertSame('Sara', $decoded['profile']['first_name']);
    }

    public function test_user_can_download_generated_private_data_export_and_stranger_is_forbidden(): void
    {
        $user = User::factory()->create(['password' => 'pass123', 'role' => 'user']);
        $stranger = User::factory()->create(['role' => 'user']);

        $filePath = "private/exports/data_export_{$user->id}_1.json";
        Storage::disk('local')->put($filePath, json_encode(['test' => 'export_data']));

        $export = AccountDataExport::create([
            'user_id' => $user->id,
            'status' => AccountDataExport::STATUS_COMPLETED,
            'file_path' => $filePath,
            'data' => ['test' => 'export_data'],
            'expires_at' => now()->addDays(7),
            'completed_at' => now(),
        ]);

        // 1. Owner can download export
        $this->actingAs($user, 'api')
            ->get("/api/v1/account/data-export/{$export->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/json');

        // 2. Stranger is forbidden
        $this->actingAs($stranger, 'api')
            ->getJson("/api/v1/account/data-export/{$export->id}/download")
            ->assertForbidden();

        // 3. Expired export returns 410 Gone
        $export->update(['expires_at' => now()->subDay()]);
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/account/data-export/{$export->id}/download")
            ->assertStatus(410);
    }

    public function test_process_expired_account_deletions_command_anonymizes_pii_and_revokes_sessions(): void
    {
        $expiredUser = User::factory()->create([
            'username' => 'to_be_deleted',
            'email' => 'delete_me@example.test',
            'role' => 'user',
            'status' => 'active',
            'deletion_scheduled_for' => now()->subMinutes(10),
            'deactivation_reason' => 'GDPR Right to be Forgotten.',
        ]);

        Profile::create([
            'user_id' => $expiredUser->id,
            'first_name' => 'Private',
            'last_name' => 'Name',
            'phone' => '+2348099998888',
        ]);

        Address::create([
            'user_id' => $expiredUser->id,
            'recipient_name' => 'Private Recipient',
            'phone' => '+2348099998888',
            'line1' => 'Secret Home Address',
            'city' => 'Abuja',
            'state' => 'FCT',
            'country' => 'NG',
            'type' => 'delivery',
        ]);

        Order::create([
            'user_id' => $expiredUser->id,
            'number' => 'ORD-DEL-01',
            'currency' => 'NGN',
            'subtotal' => '10000.00',
            'discount_total' => '0.00',
            'credit_total' => '0.00',
            'shipping_total' => '1000.00',
            'grand_total' => '11000.00',
            'total_amount' => '11000.00',
            'status' => 'delivered',
            'payment_status' => 'paid',
            'fulfilment_status' => 'delivered',
            'payment_method' => 'card',
            'contact_email' => $expiredUser->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => 'Old Address'],
        ]);

        // Insert active session
        $sessionId = (string) Str::uuid();
        DB::table('auth_sessions')->insert([
            'id' => $sessionId,
            'user_id' => $expiredUser->id,
            'device_name' => 'PHPUnit Test Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'last_used_at' => now(),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // User whose deletion is scheduled in the future (within grace period)
        $futureUser = User::factory()->create([
            'username' => 'future_delete',
            'email' => 'future@example.test',
            'status' => 'active',
            'deletion_scheduled_for' => now()->addDays(10),
        ]);

        // Run the command
        $this->artisan('account:anonymize-expired')->assertSuccessful();

        $expiredUser->refresh();
        $this->assertSame(UserStatus::Deactivated, $expiredUser->status);
        $this->assertNull($expiredUser->deletion_scheduled_for);
        $this->assertNotNull($expiredUser->anonymized_at);
        $this->assertStringStartsWith('deleted_user_', $expiredUser->username);
        $this->assertStringEndsWith('@deleted.invalid', $expiredUser->email);

        // Profile scrubbed
        $this->assertSame('Anonymized', $expiredUser->profile->first_name);
        $this->assertNull($expiredUser->profile->phone);

        // Address scrubbed
        $address = $expiredUser->addresses()->first();
        $this->assertSame('Anonymized Recipient', $address->recipient_name);
        $this->assertSame('+00000000000', $address->phone);
        $this->assertSame('Redacted', $address->line1);

        // Session revoked
        $this->assertDatabaseMissing('auth_sessions', [
            'id' => $sessionId,
        ]);

        // Order preserved for accounting/tax compliance
        $this->assertDatabaseHas('orders', [
            'number' => 'ORD-DEL-01',
            'user_id' => $expiredUser->id,
            'grand_total' => '11000.00',
        ]);

        // Audit event recorded
        $this->assertDatabaseHas('audit_events', [
            'action' => 'account.anonymized',
            'subject_id' => $expiredUser->id,
        ]);

        // Future user remains untouched
        $futureUser->refresh();
        $this->assertSame(UserStatus::Active, $futureUser->status);
        $this->assertSame('future@example.test', $futureUser->email);
    }

    public function test_ensure_age_verified_middleware_protects_restricted_content(): void
    {
        Route::middleware(['age.verified'])->get('/test-age-restricted-endpoint', function () {
            return response()->json(['success' => true, 'message' => 'Restricted content unlocked.']);
        });

        // 1. Unverified guest without token rejected with 403
        $this->getJson('/test-age-restricted-endpoint')
            ->assertStatus(403)
            ->assertJsonPath('code', 'AGE_VERIFICATION_REQUIRED');

        // 2. Unverified authenticated user rejected with 403
        $unverifiedUser = User::factory()->create(['is_age_verified' => false, 'role' => 'user']);
        $this->actingAs($unverifiedUser, 'api')
            ->getJson('/test-age-restricted-endpoint')
            ->assertStatus(403)
            ->assertJsonPath('code', 'AGE_VERIFICATION_REQUIRED');

        // 3. Verified guest with cached token allowed
        Cache::put('age_grant:guest-token-abc', true, now()->addHour());
        $this->withHeader('X-Guest-Token', 'guest-token-abc')
            ->getJson('/test-age-restricted-endpoint')
            ->assertOk()
            ->assertJsonPath('message', 'Restricted content unlocked.');

        // 4. Verified authenticated user allowed
        $verifiedUser = User::factory()->create(['is_age_verified' => true, 'role' => 'user']);
        $this->actingAs($verifiedUser, 'api')
            ->getJson('/test-age-restricted-endpoint')
            ->assertOk()
            ->assertJsonPath('message', 'Restricted content unlocked.');
    }
}
