<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PrivacyConsentResource;
use App\Models\Privacy\PrivacyConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivacyConsentController extends BaseController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $guestToken = $request->header('X-Guest-Token', $request->query('guest_token'));

        $consent = null;
        if ($user) {
            $consent = PrivacyConsent::query()
                ->where('user_id', $user->id)
                ->latest('consented_at')
                ->first();
        } elseif ($guestToken) {
            $consent = PrivacyConsent::query()
                ->where('guest_token', $guestToken)
                ->latest('consented_at')
                ->first();
        }

        if (! $consent) {
            $consent = new PrivacyConsent([
                'policy_version' => '1.0',
                'essential' => true,
                'analytics' => false,
                'marketing' => false,
                'third_party_sharing' => false,
                'personalized_ads' => false,
                'source' => 'web',
                'consented_at' => null,
            ]);
        }

        return $this->success(
            new PrivacyConsentResource($consent),
            'Privacy consents retrieved successfully.'
        );
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'policy_version' => ['sometimes', 'string', 'max:20'],
            'analytics' => ['required', 'boolean'],
            'marketing' => ['required', 'boolean'],
            'third_party_sharing' => ['required', 'boolean'],
            'personalized_ads' => ['sometimes', 'boolean'],
            'source' => ['sometimes', 'string', 'max:50'],
            'guest_token' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $user = $request->user('api');
        $guestToken = $validated['guest_token'] ?? $request->header('X-Guest-Token');

        $ipHash = $request->ip() ? hash('sha256', $request->ip().(config('app.key') ?? 'limitra')) : null;

        $consent = PrivacyConsent::create([
            'user_id' => $user?->id,
            'guest_token' => $user ? null : $guestToken,
            'policy_version' => $validated['policy_version'] ?? '1.0',
            'essential' => true,
            'analytics' => (bool) $validated['analytics'],
            'marketing' => (bool) $validated['marketing'],
            'third_party_sharing' => (bool) $validated['third_party_sharing'],
            'personalized_ads' => (bool) ($validated['personalized_ads'] ?? false),
            'source' => $validated['source'] ?? 'web',
            'ip_hash' => $ipHash,
            'user_agent' => $request->userAgent(),
            'consented_at' => now(),
        ]);

        // If user is authenticated, keep marketing flags synchronized in user preferences if present
        if ($user && $user->preference) {
            $user->preference->update([
                'marketing_emails' => (bool) $validated['marketing'],
                'marketing_sms' => (bool) $validated['marketing'],
                'marketing_push' => (bool) $validated['marketing'],
                'marketing_in_app' => (bool) $validated['marketing'],
            ]);
        }

        return $this->success(
            new PrivacyConsentResource($consent),
            'Privacy consents updated successfully.'
        );
    }
}
