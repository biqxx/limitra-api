<?php

namespace App\Services\Admin;

use App\Models\Admin\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditEventService
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'password',
        'secret',
        'token',
        'otp',
        'card_number',
        'cvc',
        'cvv',
        'account_number',
        'bank_account',
        'provider_payload',
    ];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditEvent {
        $request ??= $this->currentRequest();
        $requestActor = $request?->user('api');

        if ($actor === null && $requestActor instanceof User) {
            $actor = $requestActor;
        }

        return AuditEvent::query()->create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'reason' => $reason,
            'before_values' => $before === null ? null : $this->sanitize($before),
            'after_values' => $after === null ? null : $this->sanitize($after),
            'metadata' => $this->sanitize($metadata),
            'request_id' => $this->requestId($request),
            'ip_address' => $request?->ip(),
            'user_agent' => $this->userAgent($request),
            'created_at' => now(),
        ]);
    }

    private function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    private function requestId(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        return $request->header('X-Correlation-ID')
            ?? $request->header('X-Request-ID')
            ?? (string) Str::uuid();
    }

    private function userAgent(?Request $request): ?string
    {
        $userAgent = $request?->userAgent();

        return $userAgent === null ? null : Str::limit($userAgent, 512, '');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->sanitize($value);
            }
        }

        return $values;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = Str::of($key)->snake()->lower()->toString();

        return Str::contains($normalizedKey, self::SENSITIVE_KEYS);
    }
}
