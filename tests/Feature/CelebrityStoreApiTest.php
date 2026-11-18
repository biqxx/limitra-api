<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Settings\BusinessSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CelebrityStoreApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_celebrity_stores_and_microstores_have_separate_endpoints_and_isolation(): void
    {
        Notification::fake();

        BusinessSetting::updateOrCreate(
            ['key' => 'microstores.require_moderation'],
            ['value' => true, 'type' => 'boolean', 'group' => 'microstores']
        );

        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        // Register a regular microstore
        $microstoreRes = $this->actingAs($user, 'api')
            ->postJson('/api/v1/my-microstore', ['name' => 'Creator Pick'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'microstore');

        // Register a celebrity store for the same user via the dedicated endpoint
        $celebrityRes = $this->actingAs($user, 'api')
            ->postJson('/api/v1/my-celebrity-store', ['name' => 'Star Vault'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'celebrity');

        $microstoreId = $microstoreRes->json('data.id');
        $celebrityId = $celebrityRes->json('data.id');

        // Admin celebrity-stores endpoint cannot moderate a regular microstore
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/celebrity-stores/{$microstoreId}/moderate", [
                'moderation_status' => 'approved',
            ])
            ->assertNotFound();

        // Admin approves each via its respective endpoint
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/microstores/{$microstoreId}/moderate", [
                'moderation_status' => 'approved',
            ])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/celebrity-stores/{$celebrityId}/moderate", [
                'moderation_status' => 'approved',
            ])
            ->assertOk();

        // Public endpoints only return stores of their respective type
        $this->getJson('/api/v1/microstores')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $microstoreId);

        $this->getJson('/api/v1/celebrity-stores')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $celebrityId);
    }
}

