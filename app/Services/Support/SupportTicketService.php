<?php

namespace App\Services\Support;

use App\Models\Order\Order;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupportTicketService
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
        private readonly SupportAttachmentService $attachments,
        private readonly SupportTicketNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function create(array $data, ?User $user, array $files = []): SupportTicket
    {
        if ($user === null && ! $this->settings->value('support.guest_tickets_enabled')) {
            throw ValidationException::withMessages([
                'contact_email' => ['Guest support requests are currently unavailable. Please sign in to continue.'],
            ]);
        }

        $order = $this->resolveOrder($data['order_id'] ?? null, $user, $data['contact_email'] ?? null);
        $openedAt = now();

        $storedPaths = [];

        try {
            $ticket = DB::transaction(function () use ($data, $user, $order, $openedAt, $files, &$storedPaths): SupportTicket {
                $ticket = SupportTicket::query()->create([
                    'number' => 'SUP-'.Str::ulid(),
                    'user_id' => $user?->id,
                    'order_id' => $order?->id,
                    'category' => $data['category'],
                    'subject' => $data['subject'],
                    'status' => 'open',
                    'priority' => 'normal',
                    'contact_name' => $data['contact_name'] ?? $this->nameFor($user),
                    'contact_email' => $data['contact_email'] ?? $user?->email,
                    'first_response_due_at' => $openedAt->copy()->addMinutes(
                        (int) $this->settings->value('support.first_response_minutes'),
                    ),
                    'resolution_due_at' => $openedAt->copy()->addMinutes(
                        (int) $this->settings->value('support.resolution_minutes'),
                    ),
                    'last_message_at' => $openedAt,
                ]);

                $message = $ticket->messages()->create([
                    'sender_id' => $user?->id,
                    'sender_type' => 'customer',
                    'message' => $data['message'],
                ]);
                $storedPaths = $this->attachments->store($message, $files);

                return $ticket->load([
                    'order',
                    'assignee',
                    'messages.sender',
                    'messages.attachments',
                ])->loadCount('messages');
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }

        $this->notifications->notifyStaff($ticket, 'created');

        return $ticket;
    }

    private function resolveOrder(mixed $orderId, ?User $user, ?string $contactEmail): ?Order
    {
        if ($orderId === null) {
            return null;
        }

        $order = Order::query()
            ->whereKey($orderId)
            ->when(
                $user,
                fn ($query) => $query->where('user_id', $user->id),
                fn ($query) => $query->where('contact_email', $contactEmail),
            )
            ->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => ['The selected order is invalid.'],
            ]);
        }

        return $order;
    }

    private function nameFor(?User $user): string
    {
        if (! $user) {
            return '';
        }

        $user->loadMissing('profile');

        return $user->profile?->full_name ?: $user->username;
    }
}
