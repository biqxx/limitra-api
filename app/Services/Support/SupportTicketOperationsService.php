<?php

namespace App\Services\Support;

use App\Models\Support\SupportTicket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupportTicketOperationsService
{
    private const TRANSITIONS = [
        'open' => ['pending', 'waiting_on_customer', 'resolved', 'closed'],
        'pending' => ['open', 'waiting_on_customer', 'resolved', 'closed'],
        'waiting_on_customer' => ['open', 'pending', 'resolved', 'closed'],
        'resolved' => ['open', 'closed'],
        'closed' => ['open'],
    ];

    public function __construct(
        private readonly SupportAttachmentService $attachments,
        private readonly SupportTicketNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function addMessage(SupportTicket $ticket, array $data, User $actor, array $files): SupportTicket
    {
        $storedPaths = [];

        try {
            $result = DB::transaction(function () use ($ticket, $data, $actor, $files, &$storedPaths): SupportTicket {
                $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
                if ($ticket->status === 'closed') {
                    throw ValidationException::withMessages([
                        'ticket' => ['Closed support tickets cannot receive new messages.'],
                    ]);
                }

                $staffReply = $actor->isStaff();
                $previousStatus = $ticket->status;
                if ($staffReply) {
                    $ticket->first_responded_at ??= now();
                    if (in_array($ticket->status, ['open', 'pending'], true)) {
                        $ticket->status = 'waiting_on_customer';
                    }
                } elseif (in_array($ticket->status, ['waiting_on_customer', 'resolved'], true)) {
                    $ticket->status = 'open';
                    $ticket->resolved_at = null;
                }

                $message = $ticket->messages()->create([
                    'sender_id' => $actor->id,
                    'sender_type' => $staffReply ? 'staff' : 'customer',
                    'message' => $data['message'] ?? '',
                ]);
                $storedPaths = $this->attachments->store($message, $files);
                $ticket->last_message_at = now();
                $ticket->save();

                $ticket->events()->create([
                    'actor_id' => $actor->id,
                    'actor_type' => $staffReply ? 'staff' : 'customer',
                    'event' => 'message_added',
                    'changes' => $previousStatus === $ticket->status ? null : [
                        'status' => ['from' => $previousStatus, 'to' => $ticket->status],
                    ],
                    'metadata' => ['message_id' => $message->id],
                    'created_at' => now(),
                ]);

                return $this->load($ticket);
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }

        if ($actor->isStaff()) {
            $this->notifications->notifyCustomer($result, 'reply', $data['message'] ?? null);
        } else {
            $this->notifications->notifyStaff($result, 'customer_reply');
        }

        return $result;
    }

    public function close(SupportTicket $ticket, User $actor): SupportTicket
    {
        $result = DB::transaction(function () use ($ticket, $actor): SupportTicket {
            $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($ticket->status === 'closed') {
                throw ValidationException::withMessages(['ticket' => ['The support ticket is already closed.']]);
            }

            $from = $ticket->status;
            $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            $ticket->events()->create([
                'actor_id' => $actor->id,
                'actor_type' => $actor->isStaff() ? 'staff' : 'customer',
                'event' => 'closed',
                'changes' => ['status' => ['from' => $from, 'to' => 'closed']],
                'created_at' => now(),
            ]);

            return $this->load($ticket);
        }, 3);

        if ($actor->isStaff()) {
            $this->notifications->notifyCustomer($result, 'status_updated');
        } else {
            $this->notifications->notifyStaff($result, 'customer_closed');
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    public function update(SupportTicket $ticket, array $data, User $actor): SupportTicket
    {
        [$result, $statusChanged] = DB::transaction(function () use ($ticket, $data, $actor): array {
            $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $changes = [];

            foreach (['status', 'priority'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== $ticket->{$field}) {
                    if ($field === 'status') {
                        $this->validateTransition($ticket->status, $data[$field]);
                    }
                    $changes[$field] = ['from' => $ticket->{$field}, 'to' => $data[$field]];
                    $ticket->{$field} = $data[$field];
                }
            }

            if (array_key_exists('assignee_id', $data) && $data['assignee_id'] !== $ticket->assigned_to) {
                $changes['assignee_id'] = ['from' => $ticket->assigned_to, 'to' => $data['assignee_id']];
                $ticket->assigned_to = $data['assignee_id'];
            }

            if (isset($changes['status'])) {
                $ticket->resolved_at = $ticket->status === 'resolved' ? now() : null;
                $ticket->closed_at = $ticket->status === 'closed' ? now() : null;
            }

            if ($changes !== []) {
                $ticket->save();
                $ticket->events()->create([
                    'actor_id' => $actor->id,
                    'actor_type' => 'staff',
                    'event' => 'updated',
                    'changes' => $changes,
                    'created_at' => now(),
                ]);
            }

            return [$this->load($ticket), isset($changes['status'])];
        }, 3);

        if ($statusChanged) {
            $this->notifications->notifyCustomer($result, 'status_updated');
        }

        return $result;
    }

    private function validateTransition(string $from, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ["Support ticket cannot transition from {$from} to {$to}."],
            ]);
        }
    }

    private function load(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load([
            'order',
            'assignee',
            'messages.sender',
            'messages.attachments',
            'events.actor',
        ])->loadCount('messages');
    }
}
