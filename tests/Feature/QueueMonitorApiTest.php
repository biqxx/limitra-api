<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Jobs\RetryFailedJob;
use Mockery\MockInterface;
use Tests\TestCase;

class QueueMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
    }

    public function test_admin_can_read_sanitized_queue_status(): void
    {
        $this->mockStatusRepositories();

        $this->actingAs($this->user('admin'), 'api')
            ->getJson('/api/v1/admin/queue/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.processes', 2)
            ->assertJsonPath('data.jobs.pending', 4)
            ->assertJsonPath('data.workload.0.queue', 'default');
    }

    public function test_staff_can_read_metrics_but_regular_users_cannot(): void
    {
        $this->mockEmptyStatusRepositories();
        $this->mock(MetricsRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('measuredQueues')->once()->andReturn(['default']);
            $mock->shouldReceive('throughputForQueue')->with('default')->once()->andReturn(12);
            $mock->shouldReceive('runtimeForQueue')->with('default')->once()->andReturn(41.255);
            $mock->shouldReceive('snapshotsForQueue')->with('default')->once()->andReturn([['throughput' => 12]]);
            $mock->shouldReceive('jobsProcessedPerMinute')->once()->andReturn(3);
            $mock->shouldReceive('throughput')->once()->andReturn(12);
        });

        $this->actingAs($this->user('staff'), 'api')
            ->getJson('/api/v1/admin/queue/metrics')
            ->assertOk()
            ->assertJsonPath('data.jobs_per_minute', 3)
            ->assertJsonPath('data.queues.0.runtime_ms', 41.26);

        $this->actingAs($this->user('user'), 'api')
            ->getJson('/api/v1/admin/queue/metrics')
            ->assertForbidden();
    }

    public function test_failed_job_api_omits_payloads_and_exception_traces(): void
    {
        $this->mockEmptyStatusRepositories();
        $this->mock(JobRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getFailed')->with(-1)->once()->andReturn(collect([(object) [
                'id' => 'failed-job-1',
                'index' => 0,
                'name' => 'App\\Jobs\\ExampleJob',
                'connection' => 'redis',
                'queue' => 'default',
                'status' => 'failed',
                'failed_at' => '1700000000.5',
                'retried_by' => '[]',
                'payload' => '{"secret":"must-not-leak"}',
                'exception' => 'Sensitive stack trace',
            ]]));
            $mock->shouldReceive('countFailed')->once()->andReturn(1);
        });

        $response = $this->actingAs($this->user('admin'), 'api')
            ->getJson('/api/v1/admin/queue/failed-jobs')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', 'failed-job-1');

        $this->assertArrayNotHasKey('payload', $response->json('data.items.0'));
        $this->assertArrayNotHasKey('exception', $response->json('data.items.0'));
    }

    public function test_only_admin_can_retry_a_failed_job(): void
    {
        Bus::fake();
        $this->mockEmptyStatusRepositories();
        $this->mock(JobRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findFailed')->with('failed-job-1')->once()->andReturn((object) [
                'id' => 'failed-job-1',
            ]);
        });

        $this->actingAs($this->user('staff'), 'api')
            ->postJson('/api/v1/admin/queue/failed-jobs/failed-job-1/retry')
            ->assertForbidden();

        $this->actingAs($this->user('admin'), 'api')
            ->postJson('/api/v1/admin/queue/failed-jobs/failed-job-1/retry')
            ->assertStatus(202);

        Bus::assertDispatched(RetryFailedJob::class, fn (RetryFailedJob $job): bool => $job->id === 'failed-job-1');
    }

    public function test_horizon_dashboard_route_is_registered(): void
    {
        $this->assertTrue(Route::has('horizon.index'));
    }

    public function test_queue_api_returns_json_for_unauthenticated_browser_requests(): void
    {
        $this->get('/api/v1/admin/queue/status')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    private function mockStatusRepositories(): void
    {
        $this->mock(JobRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('countPending')->once()->andReturn(4);
            $mock->shouldReceive('countCompleted')->once()->andReturn(10);
            $mock->shouldReceive('countFailed')->once()->andReturn(1);
            $mock->shouldReceive('countRecent')->once()->andReturn(14);
        });
        $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('all')->once()->andReturn([(object) ['status' => 'running']]);
        });
        $this->mock(SupervisorRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('all')->once()->andReturn([(object) ['processes' => ['redis:default' => 2]]]);
        });
        $this->mock(WorkloadRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->once()->andReturn([[
                'name' => 'default',
                'length' => 4,
                'wait' => 2,
                'processes' => 2,
            ]]);
        });
        $this->mock(MetricsRepository::class);
    }

    private function mockEmptyStatusRepositories(): void
    {
        $this->mock(MasterSupervisorRepository::class);
        $this->mock(SupervisorRepository::class);
        $this->mock(WorkloadRepository::class);
        $this->mock(MetricsRepository::class);
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
