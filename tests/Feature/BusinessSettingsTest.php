<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Admin\AuditEvent;
use App\Models\Settings\BusinessSetting;
use App\Models\Settings\BusinessSettingChange;
use App\Models\User;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_can_list_and_filter_database_backed_settings(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/settings?group=returns')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.group', 'returns')
            ->assertJsonPath('data.1.group', 'returns')
            ->assertJsonPath('data.2.group', 'returns');
    }

    public function test_only_admin_can_manage_business_settings(): void
    {
        $staff = $this->user('staff');
        $customer = $this->user('user');
        $payload = ['settings' => [['key' => 'returns.window_days', 'value' => 21]]];

        $this->actingAs($staff, 'api')->patchJson('/api/v1/admin/settings', $payload)->assertForbidden();
        $this->actingAs($customer, 'api')->patchJson('/api/v1/admin/settings', $payload)->assertForbidden();
    }

    public function test_admin_updates_are_atomic_audited_and_available_through_the_cached_service(): void
    {
        $admin = $this->user('admin');
        $service = app(BusinessSettingsService::class);
        $this->assertSame(14, $service->value('returns.window_days'));

        $this->actingAs($admin, 'api')->patchJson('/api/v1/admin/settings', [
            'settings' => [
                ['key' => 'returns.window_days', 'value' => 30],
                ['key' => 'referrals.reward_amount_minor', 'value' => 900000],
            ],
        ])->assertOk()
            ->assertJsonPath('message', 'Business settings updated.')
            ->assertJsonPath('data.0.value', 30)
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.1.value', 900000);

        $this->assertSame(30, $service->value('returns.window_days'));
        $this->assertDatabaseHas('business_setting_changes', [
            'business_setting_id' => BusinessSetting::where('key', 'returns.window_days')->value('id'),
            'changed_by' => $admin->id,
            'version' => 2,
        ]);
        $this->assertSame(2, BusinessSettingChange::query()->count());
        $this->assertSame(2, AuditEvent::query()->where('action', 'business_setting.updated')->count());
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'subject_type' => BusinessSetting::class,
            'subject_id' => BusinessSetting::where('key', 'returns.window_days')->value('id'),
        ]);
    }

    public function test_invalid_or_unknown_values_do_not_partially_update_settings(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')->patchJson('/api/v1/admin/settings', [
            'settings' => [
                ['key' => 'returns.window_days', 'value' => 30],
                ['key' => 'referrals.missing', 'value' => true],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings.1.key');

        $this->assertSame(14, BusinessSetting::where('key', 'returns.window_days')->value('value'));
        $this->assertDatabaseCount('business_setting_changes', 0);

        $this->actingAs($admin, 'api')->patchJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'returns.window_days', 'value' => -1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings.0.value');
    }

    public function test_related_wallet_limits_are_validated_together(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')->patchJson('/api/v1/admin/settings', [
            'settings' => [
                ['key' => 'wallet.minimum_deposit_minor', 'value' => 50000],
                ['key' => 'wallet.maximum_deposit_minor', 'value' => 10000],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings');

        $this->assertSame(10000, BusinessSetting::where('key', 'wallet.minimum_deposit_minor')->value('value'));
    }

    public function test_admin_can_view_setting_change_history(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')->patchJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'returns.window_days', 'value' => 21]],
        ])->assertOk();

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/settings/returns.window_days/history')
            ->assertOk()
            ->assertJsonPath('data.items.0.old_value', 14)
            ->assertJsonPath('data.items.0.new_value', 21)
            ->assertJsonPath('data.items.0.changed_by.id', $admin->id);
    }

    public function test_ai_models_are_private_database_backed_settings_with_slug_validation(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/settings?group=ai')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.is_public', false)
            ->assertJsonPath('data.0.value', 'upstage/solar-pro4')
            ->assertJsonPath('data.1.value', 'deepseek/deepseek-v4-flash-0731')
            ->assertJsonPath('data.2.value', 'inclusionai/ling-3.0-flash');

        $this->actingAs($admin, 'api')->patchJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'ai.primary_model', 'value' => 'invalid model slug']],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings.0.value');

        $this->actingAs($admin, 'api')->patchJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'ai.primary_model', 'value' => 'provider/replacement-model']],
        ])->assertOk()->assertJsonPath('data.0.value', 'provider/replacement-model');
    }

    private function user(string $role): User
    {
        return User::create([
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
