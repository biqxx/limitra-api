<?php

namespace App\Http\Controllers\Api;

use App\Jobs\Privacy\ProcessAccountDataExportJob;
use App\Models\Privacy\AccountDataExport;
use App\Models\User;
use App\Models\User\Role;
use App\Services\Admin\AuditEventService;
use App\Services\Auth\AuthSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountComplianceController extends BaseController
{
    public function exportData(Request $request, AuditEventService $audits): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'async' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user('api');

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password does not match our records.'],
            ]);
        }

        $export = AccountDataExport::create([
            'user_id' => $user->id,
            'status' => AccountDataExport::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        if ($request->boolean('async')) {
            ProcessAccountDataExportJob::dispatch($export->id);

            $audits->record(
                action: 'account.data_export_requested',
                actor: $user,
                subject: $export,
                metadata: ['export_id' => $export->id, 'async' => true],
                request: $request,
            );

            return $this->success([
                'export_id' => $export->id,
                'status' => 'pending',
                'expires_at' => $export->expires_at->toIso8601String(),
            ], 'Account data export request queued successfully.', 202);
        }

        (new ProcessAccountDataExportJob($export->id))->handle();
        $export->refresh();

        $audits->record(
            action: 'account.data_exported',
            actor: $user,
            subject: $export,
            metadata: ['export_id' => $export->id, 'async' => false],
            request: $request,
        );

        return $this->success([
            'export_id' => $export->id,
            'status' => $export->status,
            'download_url' => route('account.data-export.download', ['export' => $export->id]),
            'expires_at' => $export->expires_at->toIso8601String(),
            'data' => $export->data,
        ], 'Account data export generated successfully.', 201);
    }

    public function getExport(Request $request, AccountDataExport $export): JsonResponse
    {
        $user = $request->user('api');

        if ($export->user_id !== $user->id) {
            return $this->error('You are not authorized to view this export.', 403);
        }

        if ($export->expires_at->isPast()) {
            return $this->error('The data export download link has expired.', 410);
        }

        return $this->success([
            'export_id' => $export->id,
            'status' => $export->status,
            'download_url' => $export->file_path ? route('account.data-export.download', ['export' => $export->id]) : null,
            'expires_at' => $export->expires_at->toIso8601String(),
            'data' => $export->data,
        ], 'Account data export retrieved successfully.');
    }

    public function downloadExport(Request $request, AccountDataExport $export): StreamedResponse|JsonResponse
    {
        $user = $request->user('api');

        if ($export->user_id !== $user->id) {
            return $this->error('You are not authorized to download this export.', 403);
        }

        if ($export->expires_at->isPast()) {
            return $this->error('The data export download link has expired.', 410);
        }

        if (! $export->file_path || ! Storage::disk('local')->exists($export->file_path)) {
            return $this->error('The export file is not available.', 404);
        }

        return Storage::disk('local')->download(
            $export->file_path,
            "limitra_data_export_{$user->id}.json",
            ['Content-Type' => 'application/json']
        );
    }

    public function requestDeletion(
        Request $request,
        AuditEventService $audits,
        AuthSessionManager $sessions,
    ): JsonResponse {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
            'confirm' => ['required', 'boolean', 'accepted'],
        ]);

        /** @var User $user */
        $user = $request->user('api');

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password does not match our records.'],
            ]);
        }

        if ($user->role === 'admin') {
            $superAdminRole = Role::query()->where('is_super_admin', true)->first();
            if ($superAdminRole && $superAdminRole->users()->whereKey($user->id)->exists()) {
                $count = $superAdminRole->users()->where('users.id', '!=', $user->id)->count();
                if ($count === 0) {
                    throw ValidationException::withMessages([
                        'account' => ['The last remaining administrator account cannot request deletion.'],
                    ]);
                }
            }
        }

        $scheduledFor = now()->addDays(14);
        $user->forceFill([
            'deletion_scheduled_for' => $scheduledFor,
            'deactivation_reason' => $validated['reason'] ?? 'User requested account deletion via GDPR erasure.',
        ])->save();

        $audits->record(
            action: 'account.deletion_requested',
            actor: $user,
            subject: $user,
            metadata: [
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'reason' => $validated['reason'] ?? null,
            ],
            request: $request,
        );

        return $this->success([
            'deletion_scheduled_for' => $scheduledFor->toIso8601String(),
            'grace_period_days' => 14,
        ], 'Account deletion request received. Your account is scheduled for anonymization in 14 days.');
    }

    public function cancelDeletion(Request $request, AuditEventService $audits): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');

        if (! $user->deletion_scheduled_for) {
            return $this->error('There is no active deletion request on this account.', 400);
        }

        $user->forceFill([
            'deletion_scheduled_for' => null,
            'deactivation_reason' => null,
        ])->save();

        $audits->record(
            action: 'account.deletion_cancelled',
            actor: $user,
            subject: $user,
            request: $request,
        );

        return $this->success(null, 'Account deletion request has been cancelled.');
    }
}
