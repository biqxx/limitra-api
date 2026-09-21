<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Http\Controllers\Api\Admin\UserDirectoryController;
use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Profile;
use App\Models\User\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminUserListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_authorized_staff_can_search_users_by_name_with_normalized_pagination(): void
    {
        $reader = User::factory()->staff()->create();
        $role = Role::factory()->create(['name' => 'customer_reader']);
        $role->permissions()->attach(
            Permission::query()->where('name', 'customers.read')->firstOrFail(),
        );
        $reader->roles()->attach($role, ['is_primary' => false, 'assigned_at' => now()]);

        $matchingUser = User::factory()->create([
            'username' => 'ada_customer',
            'email' => 'ada@example.test',
        ]);
        Profile::query()->create([
            'user_id' => $matchingUser->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '+2348000000001',
        ]);
        User::factory()->create(['username' => 'unrelated_customer']);

        $this->actingAs($reader, 'api')
            ->getJson('/api/v1/admin/users?q=ADA%20LOVELACE&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $matchingUser->id)
            ->assertJsonPath('data.items.0.name', 'Ada Lovelace')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.last_page', 1)
            ->assertJsonPath('data.pagination.from', 1)
            ->assertJsonPath('data.pagination.to', 1);
    }

    public function test_listing_filters_by_linked_role_status_and_inclusive_creation_dates(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::factory()->create(['name' => 'customer_support']);

        $matchingUser = User::factory()->suspended()->create([
            'username' => 'dated_match',
            'created_at' => '2026-09-10 12:00:00',
        ]);
        $matchingUser->roles()->attach($role, ['is_primary' => false, 'assigned_at' => now()]);

        $outsideDate = User::factory()->suspended()->create([
            'username' => 'outside_date',
            'created_at' => '2026-09-09 23:59:59',
        ]);
        $outsideDate->roles()->attach($role, ['is_primary' => false, 'assigned_at' => now()]);
        User::factory()->create(['username' => 'wrong_status']);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/users?role=Customer%20Support&status=suspended&from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $matchingUser->id)
            ->assertJsonPath('data.items.0.status', UserStatus::Suspended->value)
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_listing_includes_soft_deactivated_users_and_sorts_stably(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['username' => 'listing_alpha']);
        User::factory()->create(['username' => 'listing_bravo']);
        $deactivated = User::factory()->create(['username' => 'listing_zulu']);
        $deactivated->forceFill([
            'status' => UserStatus::Deactivated,
            'deactivated_at' => now(),
        ])->save();
        $deactivated->delete();

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/users?q=listing&sort=username&direction=asc&per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.from', 3)
            ->assertJsonPath('data.pagination.to', 3);

        $this->assertSame($deactivated->id, $response->json('data.items.0.id'));
        $this->assertSame(UserStatus::Deactivated->value, $response->json('data.items.0.status'));
    }

    public function test_listing_accepts_an_independent_upper_date_bound(): void
    {
        $admin = User::factory()->admin()->create();
        $included = User::factory()->create([
            'username' => 'before_upper_bound',
            'created_at' => '2026-09-10 23:59:59',
        ]);
        User::factory()->create([
            'username' => 'after_upper_bound',
            'created_at' => '2026-09-11 00:00:00',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/users?q=upper_bound&to=2026-09-10')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $included->id);
    }

    public function test_listing_requires_customer_read_permission_and_valid_query_parameters(): void
    {
        $staff = User::factory()->staff()->create();
        $customerRead = Permission::query()->where('name', 'customers.read')->firstOrFail();
        Role::query()->where('name', 'staff')->firstOrFail()->permissions()->detach($customerRead);

        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->actingAs($staff, 'api')->getJson('/api/v1/admin/users')->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'api')->getJson(
            '/api/v1/admin/users?status=removed&from=09-10-2026&to=2026-09-09&sort=password&direction=sideways&page=0&per_page=101',
        )->assertUnprocessable()->assertJsonValidationErrors([
            'status', 'from', 'to', 'sort', 'direction', 'page', 'per_page',
        ]);
    }

    public function test_access_control_route_replaces_the_legacy_admin_listing(): void
    {
        $route = Route::getRoutes()->getByName('admin.users.index');

        $this->assertNotNull($route);
        $this->assertSame(UserDirectoryController::class.'@index', $route->getActionName());
        $this->assertContains('permission:customers.read', $route->gatherMiddleware());
    }
}
