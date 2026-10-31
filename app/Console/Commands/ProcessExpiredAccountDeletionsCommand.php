<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Admin\AuditEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ProcessExpiredAccountDeletionsCommand extends Command
{
    protected $signature = 'account:anonymize-expired';

    protected $description = 'Process expired account deletion requests and anonymize personal identifying data.';

    public function handle(AuditEventService $audits): int
    {
        $now = now();
        $expiredUsers = User::query()
            ->whereNotNull('deletion_scheduled_for')
            ->where('deletion_scheduled_for', '<=', $now)
            ->where('status', '!=', UserStatus::Deactivated->value)
            ->get();

        $this->info("Found {$expiredUsers->count()} account(s) due for GDPR/NDPR anonymization.");

        $processed = 0;
        foreach ($expiredUsers as $user) {
            DB::transaction(function () use ($user, $now, $audits): void {
                /** @var User $locked */
                $locked = User::query()->lockForUpdate()->find($user->id);
                if (! $locked || $locked->status === UserStatus::Deactivated || ! $locked->deletion_scheduled_for || $locked->deletion_scheduled_for->isFuture()) {
                    return;
                }

                // 1. Revoke all active sessions
                DB::table('auth_sessions')->where('user_id', $locked->id)->delete();

                // 2. Anonymize user profile
                if ($locked->profile) {
                    $locked->profile->update([
                        'first_name' => 'Anonymized',
                        'last_name' => 'User',
                        'middle_name' => null,
                        'phone' => null,
                        'birthday' => null,
                        'avatar_url' => null,
                        'gender' => null,
                        'subscribe_to_newsletter' => false,
                    ]);
                }

                // 3. Anonymize addresses
                foreach ($locked->addresses as $address) {
                    $address->update([
                        'recipient_name' => 'Anonymized Recipient',
                        'phone' => '+00000000000',
                        'line1' => 'Redacted',
                        'line2' => null,
                        'postal_code' => null,
                    ]);
                }

                $randomTag = Str::lower(Str::random(8));

                // 4. Anonymize User credentials while keeping financial integrity
                $locked->forceFill([
                    'username' => "deleted_user_{$locked->id}_{$randomTag}",
                    'email' => "deleted_user_{$locked->id}_{$randomTag}@deleted.invalid",
                    'password' => Hash::make(Str::random(40)),
                    'status' => UserStatus::Deactivated,
                    'deletion_scheduled_for' => null,
                    'anonymized_at' => $now,
                ])->save();

                // 5. Record immutable audit event
                $audits->record(
                    action: 'account.anonymized',
                    actor: null,
                    subject: $locked,
                    metadata: [
                        'user_id' => $locked->id,
                        'reason' => $locked->deactivation_reason,
                    ],
                );
            });

            $processed++;
        }

        $this->info("Successfully anonymized {$processed} account(s).");

        return self::SUCCESS;
    }
}
