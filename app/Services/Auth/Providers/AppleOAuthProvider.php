<?php

namespace App\Services\Auth\Providers;

use App\Services\Auth\Contracts\SocialOAuthProviderContract;
use App\Services\Auth\SocialUserProfile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class AppleOAuthProvider implements SocialOAuthProviderContract
{
    public function getAuthorizationUrl(string $state, string $nonce, ?string $redirectUri = null): string
    {
        $clientId = (string) config('services.apple.client_id');
        $redirect = $redirectUri ?: (string) config('services.apple.redirect');

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope' => 'name email',
            'state' => $state,
            'nonce' => $nonce,
        ]);

        return "https://appleid.apple.com/auth/authorize?{$params}";
    }

    public function verifyCode(string $code, array $stateData, ?string $redirectUri = null): SocialUserProfile
    {
        $clientId = (string) config('services.apple.client_id');
        $clientSecret = (string) config('services.apple.client_secret');
        $redirect = $redirectUri ?: ($stateData['redirect_uri'] ?? (string) config('services.apple.redirect'));

        $response = Http::asForm()->post('https://appleid.apple.com/auth/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect,
        ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to exchange authorization code with Apple.');
        }

        $data = $response->json();
        $idToken = $data['id_token'] ?? null;

        if (! $idToken) {
            throw new InvalidArgumentException('Apple did not return an id_token.');
        }

        $claims = $this->decodeJwtPayload($idToken);

        if (($claims['iss'] ?? '') !== 'https://appleid.apple.com') {
            throw new InvalidArgumentException('Invalid token issuer from Apple.');
        }

        if (($claims['aud'] ?? '') !== $clientId) {
            throw new InvalidArgumentException('Invalid token audience from Apple.');
        }

        if (isset($claims['nonce']) && ($claims['nonce'] !== ($stateData['nonce'] ?? ''))) {
            throw new InvalidArgumentException('Invalid token nonce from Apple.');
        }

        $email = $claims['email'] ?? null;
        $emailVerified = filter_var($claims['email_verified'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if (! $email || ! $emailVerified) {
            throw new InvalidArgumentException('Apple account email is not verified.');
        }

        return new SocialUserProfile(
            id: (string) $claims['sub'],
            email: strtolower(trim((string) $email)),
            emailVerified: true,
            name: $claims['name'] ?? null,
            avatar: null,
            raw: $claims,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            throw new InvalidArgumentException('Invalid JWT structure.');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Invalid JWT payload.');
        }

        return $payload;
    }
}
