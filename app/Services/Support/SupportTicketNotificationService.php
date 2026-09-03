<?php

namespace App\Services\Support;

use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketCustomerNotification;
use App\Notifications\SupportTicketStaffNotification;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class SupportTicketNotificationService
{
    public function notifyStaff(SupportTicket $ticket, string $kind): void
    {
        $this->afterCommit(function () use ($ticket, $kind): void {
            $ticket = SupportTicket::query()->with('assignee')->find($ticket->id);
            if (! $ticket) {
                return;
            }

            $recipients = $ticket->assignee?->isStaff()
                ? collect([$ticket->assignee])
                : User::query()->whereIn('role', ['admin', 'staff'])->get();

            Notification::send($recipients, new SupportTicketStaffNotification(
                $ticket->id,
                $ticket->number,
                $ticket->subject,
                $ticket->contact_name,
                $kind,
            ));
        });
    }

    public function notifyCustomer(SupportTicket $ticket, string $kind, ?string $message = null): void
    {
        $this->afterCommit(function () use ($ticket, $kind, $message): void {
            $ticket = SupportTicket::query()->with('user')->find($ticket->id);
            if (! $ticket) {
                return;
            }

            $notification = new SupportTicketCustomerNotification(
                $ticket->id,
                $ticket->number,
                $ticket->subject,
                $ticket->status,
                $kind,
                $message,
            );

            if ($ticket->user) {
                $ticket->user->notify($notification);

                return;
            }

            Notification::route('mail', [$ticket->contact_email => $ticket->contact_name])
                ->notify($notification);
        });
    }

    private function afterCommit(Closure $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
