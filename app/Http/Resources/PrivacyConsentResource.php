<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrivacyConsentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id ?? null,
            'policy_version' => $this->policy_version ?? '1.0',
            'essential' => true,
            'analytics' => (bool) ($this->analytics ?? false),
            'marketing' => (bool) ($this->marketing ?? false),
            'third_party_sharing' => (bool) ($this->third_party_sharing ?? false),
            'personalized_ads' => (bool) ($this->personalized_ads ?? false),
            'source' => $this->source ?? 'web',
            'consented_at' => $this->consented_at ?? null,
        ];
    }
}
