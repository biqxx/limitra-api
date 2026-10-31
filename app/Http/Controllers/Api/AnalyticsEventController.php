<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RecordAnalyticsEvent;
use App\Models\Analytics\AnalyticsEvent;
use App\Models\Privacy\PrivacyConsent;
use App\Services\Analytics\AnalyticsTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AnalyticsEventController extends BaseController
{
    /**
     * Ingest a small batch of client-side analytics events.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['nullable', 'string', 'max:64'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.id' => ['nullable', 'string', 'max:64'],
            'events.*.type' => [
                'required',
                'string',
                'max:50',
                'in:page_view,product_view,product_impression,search,add_to_cart,remove_from_cart,cart_view,wishlist_add,wishlist_remove,checkout_started,checkout_step,checkout_completed,promotion_applied,promotion_rejected,referral_click,share_click,video_event,ai_event,support_event',
            ],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.page' => ['nullable', 'string', 'max:500'],
            'events.*.product_id' => ['nullable', 'integer'],
            'events.*.properties' => ['nullable', 'array'],
        ]);

        $user = $request->user('api');
        $userId = $user?->id;
        $sessionId = ! empty($validated['session_id'])
            ? mb_substr(trim($validated['session_id']), 0, 64)
            : AnalyticsTrackingService::resolveSessionId($request);

        // Check privacy consent (if user has explicitly opted out of analytics tracking)
        if ($this->hasOptedOutOfAnalytics($user, $request)) {
            return $this->success([
                'accepted' => 0,
                'dropped' => count($validated['events']),
                'reason' => 'User opted out of analytics tracking.',
            ], 'Analytics events processed according to privacy preferences.');
        }

        $accepted = 0;
        $dropped = 0;

        foreach ($validated['events'] as $eventData) {
            $eventId = ! empty($eventData['id'])
                ? trim($eventData['id'])
                : (string) Str::uuid();

            // Deduplication by client event ID
            if (AnalyticsEvent::query()->where('event_id', $eventId)->exists()) {
                $dropped++;

                continue;
            }

            $eventType = $eventData['type'];
            $occurredAt = ! empty($eventData['occurred_at'])
                ? $eventData['occurred_at']
                : now()->toISOString();

            $sanitizedProps = $this->sanitizeProperties($eventData['properties'] ?? []);
            if (! empty($eventData['page'])) {
                $sanitizedProps['page'] = mb_substr($eventData['page'], 0, 500);
            }
            if (! empty($eventData['product_id'])) {
                $sanitizedProps['product_id'] = (int) $eventData['product_id'];
            }

            $job = match ($eventType) {
                'page_view' => new RecordAnalyticsEvent(
                    RecordAnalyticsEvent::PAGE_VIEW,
                    'page_view',
                    $sessionId,
                    $userId,
                    ['url' => $sanitizedProps['page'] ?? $request->fullUrl()],
                    $this->requestContext($request),
                    $eventId,
                    $occurredAt,
                ),
                'product_view' => new RecordAnalyticsEvent(
                    RecordAnalyticsEvent::PRODUCT_VIEW,
                    'product_view',
                    $sessionId,
                    $userId,
                    array_merge(['product_id' => $eventData['product_id'] ?? null], $sanitizedProps),
                    $this->requestContext($request),
                    $eventId,
                    $occurredAt,
                ),
                'add_to_cart', 'remove_from_cart', 'cart_view' => new RecordAnalyticsEvent(
                    RecordAnalyticsEvent::CART_EVENT,
                    $eventType,
                    $sessionId,
                    $userId,
                    [
                        'productId' => $eventData['product_id'] ?? ($sanitizedProps['product_id'] ?? null),
                        'quantity' => $sanitizedProps['quantity'] ?? 1,
                        'price' => $sanitizedProps['price'] ?? null,
                    ],
                    $this->requestContext($request),
                    $eventId,
                    $occurredAt,
                ),
                'checkout_completed' => new RecordAnalyticsEvent(
                    RecordAnalyticsEvent::ORDER_EVENT,
                    'order_placed',
                    $sessionId,
                    $userId,
                    [
                        'orderId' => $sanitizedProps['order_id'] ?? null,
                        'amount' => $sanitizedProps['amount'] ?? null,
                        'metadata' => $sanitizedProps,
                    ],
                    $this->requestContext($request),
                    $eventId,
                    $occurredAt,
                ),
                default => RecordAnalyticsEvent::clientEvent(
                    $request,
                    $eventType,
                    $sessionId,
                    $userId,
                    $sanitizedProps,
                    $eventId,
                    $occurredAt,
                ),
            };

            dispatch($job);
            $accepted++;
        }

        return $this->success([
            'accepted' => $accepted,
            'dropped' => $dropped,
        ], 'Analytics events accepted for ingestion.');
    }

    private function hasOptedOutOfAnalytics(?object $user, Request $request): bool
    {
        $guestToken = $request->header('X-Guest-Token', $request->query('guest_token'));

        $consent = null;
        if ($user && isset($user->id)) {
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

        // If explicitly consented and analytics is false, respect opt-out
        return $consent !== null && $consent->analytics === false;
    }

    private function sanitizeProperties(array $properties): array
    {
        $sensitiveKeys = [
            'password', 'password_confirmation', 'token', 'access_token',
            'refresh_token', 'secret', 'authorization', 'credit_card',
            'card_number', 'cvv', 'cvc', 'pin', 'bvn', 'nin', 'ssn', 'otp',
        ];

        $sanitized = [];
        foreach ($properties as $key => $value) {
            $lowerKey = mb_strtolower((string) $key);
            if (in_array($lowerKey, $sensitiveKeys, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeProperties($value);
            } elseif (is_scalar($value) || $value === null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function requestContext(Request $request): array
    {
        return [
            'ip_address' => mb_substr((string) $request->ip(), 0, 45),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'referrer' => mb_substr((string) $request->header('Referer'), 0, 500),
            'utm_source' => mb_substr((string) $request->query('utm_source'), 0, 100),
            'utm_medium' => mb_substr((string) $request->query('utm_medium'), 0, 100),
            'utm_campaign' => mb_substr((string) $request->query('utm_campaign'), 0, 255),
        ];
    }
}
