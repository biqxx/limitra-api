<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Jobs\RetryFailedJob;

class QueueMonitorController extends BaseController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly MasterSupervisorRepository $masters,
        private readonly MetricsRepository $metrics,
        private readonly SupervisorRepository $supervisors,
        private readonly WorkloadRepository $workloads,
    ) {}

    public function status(): JsonResponse
    {
        $masters = collect($this->masters->all());
        $supervisors = collect($this->supervisors->all());
        $status = match (true) {
            $masters->isEmpty() => 'inactive',
            $masters->every(fn (object $master): bool => $master->status === 'paused') => 'paused',
            default => 'running',
        };

        return $this->success([
            'status' => $status,
            'masters' => $masters->count(),
            'supervisors' => $supervisors->count(),
            'processes' => $supervisors->sum(
                fn (object $supervisor): int => (int) collect($supervisor->processes ?? [])->sum()
            ),
            'jobs' => [
                'pending' => $this->jobs->countPending(),
                'completed' => $this->jobs->countCompleted(),
                'failed' => $this->jobs->countFailed(),
                'recent' => $this->jobs->countRecent(),
            ],
            'workload' => collect($this->workloads->get())->map(fn (array $queue): array => [
                'queue' => $queue['name'],
                'length' => (int) $queue['length'],
                'wait_seconds' => (int) $queue['wait'],
                'processes' => (int) $queue['processes'],
            ])->values(),
        ]);
    }

    public function metrics(): JsonResponse
    {
        $queues = collect($this->metrics->measuredQueues())
            ->map(fn (string $queue): array => [
                'queue' => $queue,
                'throughput' => $this->metrics->throughputForQueue($queue),
                'runtime_ms' => round($this->metrics->runtimeForQueue($queue), 2),
                'snapshots' => array_slice($this->metrics->snapshotsForQueue($queue), -24),
            ])
            ->values();

        return $this->success([
            'jobs_per_minute' => $this->metrics->jobsProcessedPerMinute(),
            'throughput' => $this->metrics->throughput(),
            'queues' => $queues,
        ]);
    }

    public function failed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'starting_at' => ['sometimes', 'integer', 'min:-1'],
        ]);
        $jobs = $this->jobs->getFailed($data['starting_at'] ?? -1)
            ->map(fn (object $job): array => [
                'id' => $job->id,
                'index' => (int) $job->index,
                'name' => $job->name,
                'connection' => $job->connection,
                'queue' => $job->queue,
                'status' => $job->status,
                'failed_at' => $this->timestamp($job->failed_at ?? null),
                'retry_count' => count(json_decode($job->retried_by ?: '[]', true) ?: []),
            ]);

        return $this->success([
            'items' => $jobs->values(),
            'total' => $this->jobs->countFailed(),
            'next_starting_at' => $jobs->count() === 50 ? $jobs->last()['index'] : null,
        ]);
    }

    public function retry(string $id): JsonResponse
    {
        if ($this->jobs->findFailed($id) === null) {
            return $this->error('Failed job not found.', 404);
        }

        dispatch(new RetryFailedJob($id));

        return $this->success(['id' => $id], 'Failed job queued for retry.', 202);
    }

    private function timestamp(string|float|int|null $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : CarbonImmutable::createFromTimestampUTC((float) $timestamp)->toIso8601String();
    }
}
