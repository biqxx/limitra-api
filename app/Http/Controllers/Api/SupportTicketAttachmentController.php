<?php

namespace App\Http\Controllers\Api;

use App\Models\Support\SupportTicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportTicketAttachmentController extends BaseController
{
    public function show(Request $request, SupportTicketAttachment $supportTicketAttachment): StreamedResponse
    {
        $attachment = $supportTicketAttachment->load('message.ticket');
        $ticket = $attachment->message->ticket;
        $user = $request->user('api');

        if ($ticket->user_id !== $user->id && ! $user->isStaff()) {
            abort(403, 'Forbidden.');
        }

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type],
        );
    }
}
