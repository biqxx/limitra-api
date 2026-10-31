<?php

namespace App\Services\Auth;

class SocialUserProfile
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name = null,
        public readonly ?string $avatar = null,
        public readonly array $raw = [],
    ) {}
}
