<?php

namespace App\Enums;

enum StaffInvitationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
}
