<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Settings\BusinessSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicSettingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_public_can_fetch_public_settings_grouped_by_domain(): void
    {
        BusinessSetting::query()->create([
            'key' => 'store.name',
            'group' => 'general',
            'label' => 'Store Name',
            'type' => 'string',
            'value' => 'Limitra Stores',
            'is_public' => true,
        ]);

        BusinessSetting::query()->create([
            'key' => 'secret.api_token',
            'group' => 'secrets',
            'label' => 'Private Token',
            'type' => 'string',
            'value' => 'secret-value-do-not-leak',
            'is_public' => false,
        ]);

        $response = $this->getJson('/api/v1/settings/public');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJson([
                'data' => [
                    'general' => [
                        'store.name' => 'Limitra Stores',
                    ],
                ],
            ])
            ->assertJsonMissing(['secret-value-do-not-leak'])
            ->assertJsonMissingPath('data.secrets');
    }
}
