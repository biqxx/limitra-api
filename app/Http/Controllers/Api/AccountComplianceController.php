<?php

namespace App\Http\Controllers\Api;

use App\Models\Privacy\AccountDataExport;
use App\Models\Privacy\PrivacyConsent;
use App\Models\User;
use App\Models\User\Role;
use App\Services\Admin\AuditEventService;
use App\Services\Auth\AuthSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountComplianceController extends BaseController
{
    public function exportData(Request $request, AuditEventService $audits): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user('api');

        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password does not match our records.'],
            ]);
        }

        $user->loadMissing(['profile', 'preference', 'addresses', 'orders', 'reviews', 'supportTickets', 'account']);

        $exportData = [
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status?->value ?? (string) $user->status,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'profile' => $user->profile ? [
                'first_name' => $user->profile->first_name,
                'middle_name' => $user->profile->middle_name,
                'last_name' => $user->profile->last_name,
                'phone' => $user->profile->phone,
                'birthday' => $user->profile->birthday?->toDateString(),
                'gender' => $user->profile->gender,
                'subscribe_to_newsletter' => $user->profile->subscribe_to_newsletter,
            ] : null,
            'preferences' => $user->preference ? [
                'theme' => $user->preference->theme,
                'locale' => $user->preference->locale,
                'currency' => $user->preference->currency,
                'notifications_enabled' => $user->preference->notifications_enabled,
                'email_notifications' => $user->preference->email_notifications,
                'sms_notifications' => $user->preference->sms_notifications,
                'push_notifications' => $user->preference->push_notifications,
                'in_app_notifications' => $user->preference->in_app_notifications,
                'marketing_emails' => $user->preference->marketing_emails,
                'marketing_sms' => $user->preference->marketing_sms,
            ] : null,
            'addresses' => $user->addresses->map(fn ($addr) => [
                'label' => $addr->label,
                'recipient_name' => $addr->recipient_name,
                'phone' => $addr->phone,
                'line1' => $addr->line1,
                'line2' => $addr->line2,
                'city' => $addr->city,
                'state' => $addr->state,
                'postal_code' => $addr->postal_code,
                'country' => $addr->country,
                'is_default' => $addr->is_default,
            ]),
            'orders' => $user->orders->map(fn ($ord) => [
                'id' => $ord->id,
                'number' => $ord->number,
                'status' => $ord->status,
                'payment_status' => $ord->payment_status,
                'currency' => $ord->currency,
                'grand_total' => (string) $ord->grand_total,
                'created_at' => $ord->created_at?->toIso8601String(),
            ]),
            'reviews' => $user->reviews->map(fn ($rev) => [
                'product_id' => $rev->product_id,
                'rating' => $rev->rating,
                'comment' => $rev->comment,
                'created_at' => $rev->created_at?->toIso8601String(),
            ]),
            'support_tickets' => $user->supportTickets->map(fn ($st) => [
                'number' => $st->number,
                'category' => $st->category,
                'subject' => $st->subject,
                'status' => $st->status,
                'created_at' => $st->created_at?->toIso8601String(),
            ]),
            'wallet' => $user->account ? [
                'currency' => $user->account->currency,
                'balance' => (string) ($user->account->balance ?? '0.00'),
            ] : null,
            'privacy_consents' => PrivacyConsent::query()
                ->where('user_id', $user->id)
                ->latest('consented_at')
                ->first(),
            'generated_at' => now()->toIso8601String(),
        ];

        $export = AccountDataExport::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'data' => $exportData,
            'expires_at' => now()->addDays(7),
        ]);

        $audits->record(
            action: 'account.data_exported',
            actor: $user,
            subject: $export,
            metadata: ['export_id' => $export->id],
            request: $request,
        );

        return $this->success([
            'export_id' => $export->id,
            'status' => 'completed',
            'expires_at' => $export->expires_at->toIso8601String(),
            'data' => $exportData,
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
            'expires_at' => $export->expires_at->toIso8601String(),
            'data' => $export->data,
        ], 'Account data export retrieved successfully.');
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
