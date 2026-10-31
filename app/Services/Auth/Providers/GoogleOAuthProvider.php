<?php

namespace App\Services\Auth\Providers;

use App\Services\Auth\Contracts\SocialOAuthProviderContract;
use App\Services\Auth\SocialUserProfile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class GoogleOAuthProvider implements SocialOAuthProviderContract
{
    public function getAuthorizationUrl(string $state, string $nonce, ?string $redirectUri = null): string
    {
        $clientId = (string) config('services.google.client_id');
        $redirect = $redirectUri ?: (string) config('services.google.redirect');

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'access_type' => 'online',
        ]);

        return "https://accounts.google.com/o/oauth2/v2/auth?{$params}";
    }

    public function verifyCode(string $code, array $stateData, ?string $redirectUri = null): SocialUserProfile
    {
        $clientId = (string) config('services.google.client_id');
        $clientSecret = (string) config('services.google.client_secret');
        $redirect = $redirectUri ?: ($stateData['redirect_uri'] ?? (string) config('services.google.redirect'));

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirect,
        ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to exchange authorization code with Google.');
        }

        $data = $response->json();
        $idToken = $data['id_token'] ?? null;
        $accessToken = $data['access_token'] ?? null;

        if ($idToken) {
            $claims = $this->decodeJwtPayload($idToken);

            $issuer = $claims['iss'] ?? '';
            if (! in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
                throw new InvalidArgumentException('Invalid token issuer from Google.');
            }

            if (($claims['aud'] ?? '') !== $clientId) {
                throw new InvalidArgumentException('Invalid token audience from Google.');
            }

            if (isset($claims['nonce']) && ($claims['nonce'] !== ($stateData['nonce'] ?? ''))) {
                throw new InvalidArgumentException('Invalid token nonce from Google.');
            }

            $email = $claims['email'] ?? null;
            $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (! $email || ! $emailVerified) {
                throw new InvalidArgumentException('Google account email is not verified.');
            }

            return new SocialUserProfile(
                id: (string) $claims['sub'],
                email: strtolower(trim((string) $email)),
                emailVerified: true,
                name: $claims['name'] ?? null,
                avatar: $claims['picture'] ?? null,
                raw: $claims,
            );
        }

        if ($accessToken) {
            $userInfo = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');
            if ($userInfo->successful()) {
                $user = $userInfo->json();
                $email = $user['email'] ?? null;
                $emailVerified = filter_var($user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (! $email || ! $emailVerified) {
                    throw new InvalidArgumentException('Google account email is not verified.');
                }

                return new SocialUserProfile(
                    id: (string) $user['sub'],
                    email: strtolower(trim((string) $email)),
                    emailVerified: true,
                    name: $user['name'] ?? null,
                    avatar: $user['picture'] ?? null,
                    raw: $user,
                );
            }
        }

        throw new InvalidArgumentException('Could not retrieve verified profile from Google.');
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
