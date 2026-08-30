<?php

namespace App\Social\Contracts;

interface SocialChannel
{
    /** Whether this channel handles the given platform string. */
    public function handles(string $platform): bool;

    /** Send a plain-text reply to the given recipient on the given platform. */
    public function sendTextMessage(string $platform, string $recipientId, string $message): void;
}
