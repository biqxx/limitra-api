<?php

namespace App\Services\Auth\Providers;

use App\Services\Auth\Contracts\SocialOAuthProviderContract;
use App\Services\Auth\SocialUserProfile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class FacebookOAuthProvider implements SocialOAuthProviderContract
{
    public function getAuthorizationUrl(string $state, string $nonce, ?string $redirectUri = null): string
    {
        $clientId = (string) config('services.facebook.client_id');
        $redirect = $redirectUri ?: (string) config('services.facebook.redirect');

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'state' => $state,
            'scope' => 'email,public_profile',
            'response_type' => 'code',
        ]);

        return "https://www.facebook.com/v19.0/dialog/oauth?{$params}";
    }

    public function verifyCode(string $code, array $stateData, ?string $redirectUri = null): SocialUserProfile
    {
        $clientId = (string) config('services.facebook.client_id');
        $clientSecret = (string) config('services.facebook.client_secret');
        $redirect = $redirectUri ?: ($stateData['redirect_uri'] ?? (string) config('services.facebook.redirect'));

        $response = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirect,
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to exchange authorization code with Facebook.');
        }

        $tokenData = $response->json();
        $accessToken = $tokenData['access_token'] ?? null;

        if (! $accessToken) {
            throw new InvalidArgumentException('Facebook did not return an access token.');
        }

        $userResponse = Http::get('https://graph.facebook.com/v19.0/me', [
            'fields' => 'id,name,email,picture.type(large)',
            'access_token' => $accessToken,
        ]);

        if (! $userResponse->successful()) {
            throw new InvalidArgumentException('Failed to retrieve user profile from Facebook.');
        }

        $userData = $userResponse->json();
        $email = $userData['email'] ?? null;

        if (! $email) {
            throw new InvalidArgumentException('Facebook account does not provide an email address.');
        }

        return new SocialUserProfile(
            id: (string) $userData['id'],
            email: strtolower(trim((string) $email)),
            emailVerified: true,
            name: $userData['name'] ?? null,
            avatar: $userData['picture']['data']['url'] ?? null,
            raw: $userData,
        );
    }
}
