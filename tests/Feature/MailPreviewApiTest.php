<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use App\Services\Mail\MailPreviewReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    private string $mailLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->mailLogPath = tempnam(sys_get_temp_dir(), 'limitra-mail-preview-');
        config([
            'logging.channels.mail_preview.path' => $this->mailLogPath,
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'mail_preview',
            'mail_preview.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->mailLogPath) && is_file($this->mailLogPath)) {
            unlink($this->mailLogPath);
        }

        parent::tearDown();
    }

    public function test_admin_can_read_only_recent_mail_messages(): void
    {
        $admin = User::factory()->admin()->create();

        Log::channel('mail_preview')->info('Unrelated internal log entry');
        Mail::mailer('log')->raw('One-time code: 123456', function ($message): void {
            $message->to('tester@example.com')->subject('Verification code');
        });

        $logged = file_get_contents($this->mailLogPath);
        $this->assertStringContainsString('Verification code', $logged);
        $this->assertStringContainsString('To:', $logged);
        $this->assertStringContainsString('Subject:', $logged);
        $lines = explode("\n", trim($logged));
        $this->assertCount(2, $lines);
        $entry = json_decode($lines[1], true);
        $this->assertIsArray($entry);
        $this->assertStringContainsString('To:', $entry['message']);
        $this->assertStringContainsString('Subject:', $entry['message']);
        $this->assertSame($this->mailLogPath, config('logging.channels.mail_preview.path'));
        $this->assertCount(1, app(MailPreviewReader::class)->recent());

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/mail-preview')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->assertStringContainsString('123456', $response->json('data.items.0.message'));
        $this->assertStringNotContainsString('Unrelated internal log entry', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_guest_and_staff_without_permission_cannot_read_mail(): void
    {
        $this->getJson('/api/v1/admin/mail-preview')->assertUnauthorized();

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'api')->getJson('/api/v1/admin/mail-preview')->assertForbidden();
    }

    public function test_permission_can_be_granted_to_a_tester_without_granting_all_staff_access(): void
    {
        $staff = User::factory()->staff()->create();
        $role = Role::factory()->create(['name' => 'mail_tester']);
        $permission = Permission::query()->where('name', 'mail_logs.read')->firstOrFail();
        $role->permissions()->attach($permission);
        $staff->roles()->attach($role, [
            'is_primary' => false,
            'assigned_at' => now(),
        ]);

        $this->actingAs($staff, 'api')
            ->getJson('/api/v1/admin/mail-preview')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_endpoint_is_disabled_by_default_and_in_production(): void
    {
        $admin = User::factory()->admin()->create();
        config(['mail_preview.enabled' => false]);

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/mail-preview')->assertNotFound();

        config(['mail_preview.enabled' => true]);
        app()->detectEnvironment(fn (): string => 'production');

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/mail-preview')->assertNotFound();
    }

    public function test_endpoint_requires_dedicated_log_mailer(): void
    {
        $admin = User::factory()->admin()->create();
        config(['mail.default' => 'array']);

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/mail-preview')->assertNotFound();
    }
}
