<?php

namespace App\Jobs\Privacy;

use App\Models\Privacy\AccountDataExport;
use App\Models\Privacy\PrivacyConsent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessAccountDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $exportId)
    {
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        /** @var AccountDataExport|null $export */
        $export = AccountDataExport::query()->find($this->exportId);
        if (! $export || $export->status === AccountDataExport::STATUS_COMPLETED) {
            return;
        }

        $export->update(['status' => AccountDataExport::STATUS_PROCESSING]);

        try {
            /** @var User|null $user */
            $user = User::query()->with([
                'profile',
                'preference',
                'addresses',
                'orders.items',
                'reviews',
                'supportTickets',
                'account.transactions',
            ])->find($export->user_id);

            if (! $user) {
                $export->update(['status' => AccountDataExport::STATUS_FAILED]);

                return;
            }

            $exportData = [
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status?->value ?? (string) $user->status,
                    'is_age_verified' => (bool) $user->is_age_verified,
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
                ])->all(),
                'orders' => $user->orders->map(fn ($ord) => [
                    'id' => $ord->id,
                    'number' => $ord->number,
                    'status' => $ord->status,
                    'payment_status' => $ord->payment_status,
                    'currency' => $ord->currency,
                    'grand_total' => (string) $ord->grand_total,
                    'created_at' => $ord->created_at?->toIso8601String(),
                ])->all(),
                'reviews' => $user->reviews->map(fn ($rev) => [
                    'product_id' => $rev->product_id,
                    'rating' => $rev->rating,
                    'comment' => $rev->comment,
                    'created_at' => $rev->created_at?->toIso8601String(),
                ])->all(),
                'support_tickets' => $user->supportTickets->map(fn ($st) => [
                    'number' => $st->number,
                    'category' => $st->category,
                    'subject' => $st->subject,
                    'status' => $st->status,
                    'created_at' => $st->created_at?->toIso8601String(),
                ])->all(),
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

            $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $filePath = "private/exports/data_export_{$user->id}_{$export->id}.json";

            Storage::disk('local')->put($filePath, $jsonContent);

            $export->update([
                'status' => AccountDataExport::STATUS_COMPLETED,
                'file_path' => $filePath,
                'data' => $exportData,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to process GDPR account data export', [
                'export_id' => $this->exportId,
                'error' => $e->getMessage(),
            ]);

            $export->update(['status' => AccountDataExport::STATUS_FAILED]);
            throw $e;
        }
    }
}
