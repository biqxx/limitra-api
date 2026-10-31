<?php

namespace App\Services\Auth\Contracts;

use App\Services\Auth\SocialUserProfile;

interface SocialOAuthProviderContract
{
    /**
     * Build the authorization redirect URL.
     */
    public function getAuthorizationUrl(string $state, string $nonce, ?string $redirectUri = null): string;

    /**
     * Exchange authorization code and verify identity directly with the provider.
     *
     * @param  array{state: string, nonce: string, provider: string, redirect_uri?: string, user_id?: int|null}  $stateData
     */
    public function verifyCode(string $code, array $stateData, ?string $redirectUri = null): SocialUserProfile;
}
