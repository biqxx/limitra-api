<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateApplication;
use App\Models\Affiliate\AffiliateResource;
use App\Models\Affiliate\AffiliateWithdrawal;
use App\Models\User\Role;
use App\Notifications\Affiliate\AffiliateWithdrawalPaidNotification;
use App\Notifications\Affiliate\AffiliateWithdrawalRejectedNotification;
use App\Services\Admin\AuditEventService;
use App\Services\Affiliate\AffiliateLedgerService;
use App\Services\Auth\PermissionResolver;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AffiliateOperationsAdminController extends BaseController
{
    public function __construct(
        private readonly AffiliateLedgerService $ledgerService,
        private readonly PermissionResolver $permissions,
        private readonly BusinessSettingsService $businessSettings
    ) {}

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
                    'current_tier' => 'Bronze',
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
                'approver:id,email,username',
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
        $admin = $request->user('api');

        if (! $this->permissions->allowsAll($admin, ['payouts.approve']) && ! $admin->isAdmin()) {
            return $this->error('Insufficient permission to approve withdrawals.', 403);
        }

        if ($withdrawal->status !== 'pending') {
            return $this->error('Withdrawal is not pending review.', 422);
        }

        $before = $withdrawal->toArray();

        $withdrawal->update([
            'status' => 'approved',
            'admin_notes' => $request->input('admin_notes', $withdrawal->admin_notes),
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        $audits->record(
            action: 'affiliate_withdrawal.approved',
            actor: $admin,
            subject: $withdrawal,
            reason: $request->input('admin_notes'),
            before: $before,
            after: $withdrawal->fresh()->toArray(),
        );

        return $this->success($withdrawal->fresh()->load(['approver:id,email,username', 'payoutMethod']), 'Withdrawal approved.');
    }

    public function startProcessingWithdrawal(Request $request, AffiliateWithdrawal $withdrawal, AuditEventService $audits): JsonResponse
    {
        $admin = $request->user('api');

        if (! $this->permissions->allowsAll($admin, ['payouts.pay']) && ! $admin->isAdmin()) {
            return $this->error('Insufficient permission to process payout payments.', 403);
        }

        if ($withdrawal->status !== 'approved') {
            return $this->error('Withdrawal must be in approved status before processing payout.', 422);
        }

        $before = $withdrawal->toArray();

        $withdrawal->update([
            'status' => 'processing',
            'admin_notes' => $request->input('admin_notes', $withdrawal->admin_notes),
        ]);

        $audits->record(
            action: 'affiliate_withdrawal.processing',
            actor: $admin,
            subject: $withdrawal,
            reason: 'Withdrawal payout transfer initiated',
            before: $before,
            after: $withdrawal->fresh()->toArray(),
        );

        return $this->success($withdrawal->fresh()->load(['approver:id,email,username', 'payoutMethod']), 'Withdrawal status set to processing.');
    }

    public function rejectWithdrawal(Request $request, AffiliateWithdrawal $withdrawal, AuditEventService $audits): JsonResponse
    {
        if (in_array($withdrawal->status, ['paid', 'rejected'], true)) {
            return $this->error("Cannot reject a withdrawal that is already {$withdrawal->status}.", 422);
        }

        $admin = $request->user('api');
        $reason = (string) $request->input('reason', $request->input('admin_notes', 'Withdrawal request rejected by administrator.'));

        return DB::transaction(function () use ($withdrawal, $admin, $reason, $request, $audits): JsonResponse {
            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::whereKey($withdrawal->affiliate_id)->lockForUpdate()->firstOrFail();
            $before = $withdrawal->toArray();

            $withdrawal->update([
                'status' => 'rejected',
                'failure_reason' => $reason,
                'admin_notes' => $request->input('admin_notes', $withdrawal->admin_notes),
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ]);

            // Release hold back to available balance
            $this->ledgerService->recordRelease(
                affiliate: $lockedAffiliate,
                amount: (float) $withdrawal->amount,
                withdrawal: $withdrawal,
                reason: $reason
            );

            $audits->record(
                action: 'affiliate_withdrawal.rejected',
                actor: $admin,
                subject: $withdrawal,
                reason: $reason,
                before: $before,
                after: $withdrawal->fresh()->toArray(),
            );

            if ($lockedAffiliate->user) {
                $lockedAffiliate->user->notify(new AffiliateWithdrawalRejectedNotification(
                    reference: $withdrawal->reference,
                    amount: (float) $withdrawal->amount,
                    currency: $withdrawal->currency ?? 'NGN',
                    reason: $reason
                ));
            }

            return $this->success($withdrawal->fresh()->load('processor:id,email,username'), 'Withdrawal rejected and held funds released.');
        });
    }

    public function markPaidWithdrawal(Request $request, AffiliateWithdrawal $withdrawal, AuditEventService $audits): JsonResponse
    {
        $admin = $request->user('api');

        if (! $this->permissions->allowsAll($admin, ['payouts.pay']) && ! $admin->isAdmin()) {
            return $this->error('Insufficient permission to pay withdrawals.', 403);
        }

        // Do not allow mark-paid directly from pending! Must be approved or processing
        if ($withdrawal->status === 'pending') {
            return $this->error('Withdrawal must be approved before it can be marked as paid.', 422);
        }

        if ($withdrawal->status === 'paid') {
            return $this->error('Withdrawal has already been marked as paid.', 422);
        }

        if ($withdrawal->status === 'rejected') {
            return $this->error('Cannot mark a rejected withdrawal as paid.', 422);
        }

        // Separation-of-duties check: approver cannot be the same admin who settles payment
        if ($withdrawal->approved_by && (int) $withdrawal->approved_by === (int) $admin->id) {
            return $this->error('Separation of duties required: approval and payment processing must be performed by different administrators.', 403);
        }

        $validated = $request->validate([
            'provider_reference' => ['required', 'string', 'max:100', 'unique:affiliate_withdrawals,provider_reference,'.$withdrawal->id],
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $providerRef = $validated['provider_reference'];

        return DB::transaction(function () use ($withdrawal, $admin, $providerRef, $validated, $audits): JsonResponse {
            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::query()
                ->where('id', $withdrawal->affiliate_id)
                ->lockForUpdate()
                ->firstOrFail();

            $beforeAffiliate = $lockedAffiliate->toArray();
            $beforeWithdrawal = $withdrawal->toArray();

            $lockedAffiliate->total_paid = (float) $lockedAffiliate->total_paid + (float) $withdrawal->amount;
            $lockedAffiliate->save();

            $withdrawal->update([
                'status' => 'paid',
                'provider_reference' => $providerRef,
                'admin_notes' => $validated['admin_notes'] ?? $withdrawal->admin_notes,
                'processed_by' => $admin->id,
                'processed_at' => now(),
                'settled_at' => now(),
            ]);

            // Record immutable settlement in ledger
            $this->ledgerService->recordSettlement(
                affiliate: $lockedAffiliate,
                amount: (float) $withdrawal->amount,
                withdrawal: $withdrawal,
                providerReference: $providerRef
            );

            $audits->record(
                action: 'affiliate_withdrawal.paid',
                actor: $admin,
                subject: $withdrawal,
                reason: 'Affiliate withdrawal payout marked complete',
                before: $beforeWithdrawal,
                after: $withdrawal->fresh()->toArray(),
                metadata: [
                    'affiliate_id' => $lockedAffiliate->id,
                    'amount' => $withdrawal->amount,
                    'provider_reference' => $providerRef,
                    'total_paid_before' => $beforeAffiliate['total_paid'],
                    'total_paid_after' => $lockedAffiliate->total_paid,
                ],
            );

            if ($lockedAffiliate->user) {
                $lockedAffiliate->user->notify(new AffiliateWithdrawalPaidNotification(
                    reference: $withdrawal->reference,
                    amount: (float) $withdrawal->amount,
                    currency: $withdrawal->currency ?? 'NGN',
                    providerReference: $providerRef
                ));
            }

            return $this->success([
                'withdrawal' => $withdrawal->fresh()->load(['payoutMethod', 'approver:id,email,username', 'processor:id,email,username']),
                'affiliate' => $lockedAffiliate->fresh(),
            ], 'Withdrawal payout marked as paid.');
        });
    }

    // ── Programme Rules Management ──────────────────────────────────────────────

    public function rules(): JsonResponse
    {
        return $this->success([
            'min_withdrawal_amount' => $this->businessSettings->value('affiliates.min_withdrawal_amount'),
            'currency' => $this->businessSettings->value('affiliates.currency'),
            'attribution_window_days' => $this->businessSettings->value('affiliates.attribution_window_days'),
            'qualification_window_days' => $this->businessSettings->value('affiliates.qualification_window_days'),
            'allowed_redirect_domains' => $this->businessSettings->value('affiliates.allowed_redirect_domains'),
            'tiers' => $this->businessSettings->value('affiliates.tiers'),
        ], 'Affiliate program rules retrieved.');
    }

    public function updateRules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'min_withdrawal_amount' => ['sometimes', 'integer', 'min:1000', 'max:5000000'],
            'attribution_window_days' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'qualification_window_days' => ['sometimes', 'integer', 'min:7', 'max:365'],
            'allowed_redirect_domains' => ['sometimes', 'array'],
            'allowed_redirect_domains.*' => ['string'],
            'tiers' => ['sometimes', 'array'],
            'tiers.*.name' => ['required_with:tiers', 'string'],
            'tiers.*.min_orders' => ['required_with:tiers', 'integer', 'min:0'],
            'tiers.*.commission_rate' => ['required_with:tiers', 'numeric', 'min:1', 'max:50'],
        ]);

        $changes = [];
        foreach ($validated as $key => $val) {
            $changes[] = [
                'key' => "affiliates.{$key}",
                'value' => $val,
            ];
        }

        if (! empty($changes)) {
            $this->businessSettings->update($changes, $request->user('api'));
        }

        return $this->rules();
    }

    // ── Marketing Resources Management ──────────────────────────────────────────

    public function resources(Request $request): JsonResponse
    {
        $paginator = AffiliateResource::query()
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

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
        ], 'Marketing resources retrieved.');
    }

    public function storeResource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:50'],
            'file' => ['required', 'file', 'max:20480'], // max 20MB
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $file = $request->file('file');
        $filename = 'affiliate_resource_'.Str::random(24).'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('affiliate_resources', $filename, 'local');

        $resource = AffiliateResource::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? 'general',
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'is_published' => (bool) ($validated['is_published'] ?? true),
        ]);

        return $this->success($resource, 'Marketing resource created successfully.', 201);
    }

    public function destroyResource(AffiliateResource $resource): JsonResponse
    {
        if (Storage::disk('local')->exists($resource->file_path)) {
            Storage::disk('local')->delete($resource->file_path);
        }

        $resource->delete();

        return $this->success(null, 'Marketing resource deleted.');
    }
}
