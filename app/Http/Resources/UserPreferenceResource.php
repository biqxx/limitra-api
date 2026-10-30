<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'theme' => $this->theme,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'notifications_enabled' => $this->notifications_enabled,
            'email_notifications' => $this->email_notifications,
            'sms_notifications' => $this->sms_notifications,
            'push_notifications' => $this->push_notifications,
            'in_app_notifications' => $this->in_app_notifications,
            'marketing_emails' => $this->marketing_emails,
            'marketing_sms' => $this->marketing_sms,
            'marketing_push' => $this->marketing_push,
            'marketing_in_app' => $this->marketing_in_app,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
