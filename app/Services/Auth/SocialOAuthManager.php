<?php

namespace App\Services\Auth;

use App\Services\Auth\Contracts\SocialOAuthProviderContract;
use App\Services\Auth\Providers\AppleOAuthProvider;
use App\Services\Auth\Providers\FacebookOAuthProvider;
use App\Services\Auth\Providers\GoogleOAuthProvider;
use InvalidArgumentException;

class SocialOAuthManager
{
    /**
     * @var array<string, SocialOAuthProviderContract>
     */
    private array $providers = [];

    public function provider(string $name): SocialOAuthProviderContract
    {
        $normalized = strtolower(trim($name));

        if (! isset($this->providers[$normalized])) {
            $this->providers[$normalized] = match ($normalized) {
                'google' => new GoogleOAuthProvider,
                'facebook' => new FacebookOAuthProvider,
                'apple' => new AppleOAuthProvider,
                default => throw new InvalidArgumentException("Unsupported social provider: {$name}."),
            };
        }

        return $this->providers[$normalized];
    }

    public function isSupported(string $name): bool
    {
        return in_array(strtolower(trim($name)), ['google', 'facebook', 'apple'], true);
    }
}
