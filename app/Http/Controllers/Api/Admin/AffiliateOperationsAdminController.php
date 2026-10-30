<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateApplication;
use App\Models\Affiliate\AffiliateWithdrawal;
use App\Models\User\Role;
use App\Services\Admin\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AffiliateOperationsAdminController extends BaseController
{
    // ── Applications ────────────────────────────────────────────────────────────

    public function applications(Request $request): JsonResponse
    {
        $query = AffiliateApplication::query()
            ->with(['user:id,email,username', 'reviewer:id,email,username'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->whereHas('user', function ($uq) use ($search): void {
                $uq->where('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function showApplication(AffiliateApplication $application): JsonResponse
    {
        $application->load(['user:id,email,username', 'reviewer:id,email,username']);

        return $this->success($application);
    }

    public function approveApplication(Request $request, AffiliateApplication $application, AuditEventService $audits): JsonResponse
    {
        if ($application->status === 'approved') {
            return $this->error('Application is already approved.', 422);
        }

        $admin = $request->user('api');

        return DB::transaction(function () use ($application, $admin, $request, $audits): JsonResponse {
            $user = $application->user;

            $affiliate = Affiliate::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'code' => Affiliate::generateCode(),
                    'slug' => Affiliate::generateSlug($user->username ?? "affiliate-{$user->id}"),
                    'commission_rate' => 5.00,
                    'status' => 'active',
                ]
            );

            $user->role = 'affiliate';
            $user->save();

            $role = Role::firstOrCreate(
                ['name' => 'affiliate'],
                ['display_name' => 'Affiliate', 'description' => 'Affiliate Partner']
            );

            if (! $user->roles()->where('roles.id', $role->id)->exists()) {
                $user->roles()->attach($role, [
                    'assigned_by' => $admin->id,
                    'assigned_at' => now(),
                    'is_primary' => false,
                ]);
            }

            $before = $application->toArray();

            $application->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'admin_notes' => $request->input('admin_notes', $application->admin_notes),
            ]);

            $audits->record(
                action: 'affiliate_application.approved',
                actor: $admin,
                subject: $application,
                reason: $request->input('admin_notes'),
                before: $before,
                after: $application->fresh()->toArray(),
            );

            return $this->success([
                'application' => $application->fresh()->load('reviewer:id,email,username'),
                'affiliate' => $affiliate,
            ], 'Affiliate application approved successfully.');
        });
    }

    public function rejectApplication(Request $request, AffiliateApplication $application, AuditEventService $audits): JsonResponse
    {
        if ($application->status === 'approved') {
            return $this->error('Cannot reject an already approved application.', 422);
        }

        $admin = $request->user('api');
        $before = $application->toArray();

        $application->update([
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $request->input('admin_notes', $application->admin_notes),
        ]);

        $audits->record(
            action: 'affiliate_application.rejected',
            actor: $admin,
            subject: $application,
            reason: $request->input('admin_notes'),
            before: $before,
            after: $application->fresh()->toArray(),
        );

        return $this->success($application->fresh()->load('reviewer:id,email,username'), 'Affiliate application rejected.');
    }

    // ── Withdrawals ─────────────────────────────────────────────────────────────

    public function withdrawals(Request $request): JsonResponse
    {
        $query = AffiliateWithdrawal::query()
            ->with([
                'affiliate.user:id,email,username',
                'payoutMethod',
                'processor:id,email,username',
            ])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('affiliate_id')) {
            $query->where('affiliate_id', $request->integer('affiliate_id'));
        }

        $paginator = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function approveWithdrawal(Request $request, AffiliateWithdrawal $withdrawal, AuditEventService $audits): JsonResponse
    {
        if ($withdrawal->status !== 'pending') {
            return $this->error('Withdrawal is not pending review.', 422);
        }

        $admin = $request->user('api');
        $before = $withdrawal->toArray();

        $withdrawal->update([
            'status' => 'approved',
            'admin_notes' => $request->input('admin_notes', $withdrawal->admin_notes),
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ]);

        $audits->record(
            action: 'affiliate_withdrawal.approved',
            actor: $admin,
            subject: $withdrawal,
            reason: $request->input('admin_notes'),
            before: $before,
            after: $withdrawal->fresh()->toArray(),
        );

        return $this->success($withdrawal->fresh()->load('processor:id,email,username'), 'Withdrawal approved.');
    }

    public function rejectWithdrawal(Request $request, AffiliateWithdrawal $withdrawal, AuditEventService $audits): JsonResponse
    {
        if (in_array($withdrawal->status, ['paid', 'rejected'], true)) {
            return $this->error("Cannot reject a withdrawal that is already {$withdrawal->status}.", 422);
        }

        $admin = $request->user('api');
        $before = $withdrawal->toArray();

        $withdrawal->update([
            'status' => 'rejected',
            'admin_notes' => $request->input('admin_notes', $withdrawal->admin_notes),
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ]);

        $audits->record(
            action: 'affiliate_withdrawal.rejected',
            actor: $admin,
            subject: $withdrawal,
            reason: $request->input('admin_notes'),
            before: $before,
            after: $withdrawal->fresh()->toArray(),
        );

        return $this->success($withdrawal->fresh()->load('processor:id,email,username'), 'Withdrawal rejected.');
    }

    public function markPaidWithdrawal(Request $request, AffiliateWithdrawal $withdrawal, AuditEventService $audits): JsonResponse
    {
        if ($withdrawal->status === 'paid') {
            return $this->error('Withdrawal has already been marked as paid.', 422);
        }

        $admin = $request->user('api');

        return DB::transaction(function () use ($withdrawal, $admin, $request, $audits): JsonResponse {
            $affiliate = Affiliate::query()
                ->where('id', $withdrawal->affiliate_id)
                ->lockForUpdate()
                ->firstOrFail();

            $beforeAffiliate = $affiliate->toArray();
            $beforeWithdrawal = $withdrawal->toArray();

            $affiliate->total_paid = (float) $affiliate->total_paid + (float) $withdrawal->amount;
            $affiliate->save();

            $withdrawal->update([
                'status' => 'paid',
                'provider_reference' => $request->input('provider_reference', $withdrawal->provider_reference),
                'admin_notes' => $request->input('admin_notes', $withdrawal->admin_notes),
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ]);

            $audits->record(
                action: 'affiliate_withdrawal.paid',
                actor: $admin,
                subject: $withdrawal,
                reason: 'Affiliate withdrawal payout marked complete',
                before: $beforeWithdrawal,
                after: $withdrawal->fresh()->toArray(),
                metadata: [
                    'affiliate_id' => $affiliate->id,
                    'amount' => $withdrawal->amount,
                    'provider_reference' => $withdrawal->provider_reference,
                    'total_paid_before' => $beforeAffiliate['total_paid'],
                    'total_paid_after' => $affiliate->total_paid,
                ],
            );

            return $this->success([
                'withdrawal' => $withdrawal->fresh()->load(['payoutMethod', 'processor:id,email,username']),
                'affiliate' => $affiliate->fresh(),
            ], 'Withdrawal payout marked as paid.');
        });
    }
}
