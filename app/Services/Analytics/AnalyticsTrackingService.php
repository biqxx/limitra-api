<?php

namespace App\Services\Analytics;

use App\Models\Analytics\AnalyticsEvent;
use App\Models\Analytics\CartEvent;
use App\Models\Analytics\OrderEvent;
use App\Models\Analytics\PageView;
use App\Models\Analytics\ProductView;
use App\Models\Analytics\TrafficSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsTrackingService
{
    public function recordQueuedEvent(
        string $eventId,
        string $kind,
        string $eventType,
        string $sessionId,
        ?int $userId,
        array $properties,
        array $context,
        string $occurredAt,
    ): void {
        DB::transaction(function () use (
            $eventId,
            $kind,
            $eventType,
            $sessionId,
            $userId,
            $properties,
            $context,
            $occurredAt,
        ): void {
            $createdAt = CarbonImmutable::parse($occurredAt);
            $event = AnalyticsEvent::firstOrCreate(
                ['event_id' => $eventId],
                [
                    'event_type' => $eventType,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'metadata' => $this->eventMetadata($kind, $properties),
                    'ip_address' => $context['ip_address'] ?? null,
                    'user_agent' => $context['user_agent'] ?? null,
                    'created_at' => $createdAt,
                ],
            );

            if (! $event->wasRecentlyCreated) {
                return;
            }

            match ($kind) {
                'page_view' => $this->createPageView($event, $sessionId, $userId, $properties, $context, $createdAt),
                'product_view' => $this->createProductView($event, $sessionId, $userId, $properties, $createdAt),
                'cart_event' => $this->createCartEvent($event, $eventType, $sessionId, $userId, $properties, $createdAt),
                'order_event' => $this->createOrderEvent($event, $eventType, $sessionId, $userId, $properties, $createdAt),
                default => throw new \InvalidArgumentException("Unsupported analytics event kind [{$kind}]."),
            };
        });
    }

    public function resolveSource(?string $referrer, ?string $utmSource = null): string
    {
        if ($utmSource) {
            return mb_strtolower($utmSource);
        }

        if (! $referrer) {
            return 'direct';
        }

        $host = mb_strtolower(parse_url($referrer, PHP_URL_HOST) ?? '');

        return match (true) {
            str_contains($host, 'google') => 'google',
            str_contains($host, 'facebook') => 'facebook',
            str_contains($host, 'instagram') => 'instagram',
            str_contains($host, 'twitter') || str_contains($host, 'x.com') => 'twitter',
            str_contains($host, 'tiktok') => 'tiktok',
            str_contains($host, 'bing') => 'bing',
            str_contains($host, 'yahoo') => 'yahoo',
            $host !== '' => 'referral',
            default => 'direct',
        };
    }

    public static function resolveSessionId(Request $request): string
    {
        $sessionId = trim((string) $request->header('X-Session-ID'));

        return $sessionId !== ''
            ? mb_substr($sessionId, 0, 64)
            : Str::uuid()->toString();
    }

    private function eventMetadata(string $kind, array $properties): ?array
    {
        $metadata = match ($kind) {
            'product_view' => ['product_id' => $properties['product_id'] ?? null],
            'cart_event' => [
                'product_id' => $properties['productId'] ?? null,
                'quantity' => $properties['quantity'] ?? null,
                'price' => $properties['price'] ?? null,
            ],
            'order_event' => array_merge(
                ['order_id' => $properties['orderId'] ?? null],
                $properties['metadata'] ?? [],
            ),
            default => [],
        };

        $metadata = array_filter($metadata, fn (mixed $value): bool => $value !== null);

        return $metadata === [] ? null : $metadata;
    }

    private function createPageView(
        AnalyticsEvent $event,
        string $sessionId,
        ?int $userId,
        array $properties,
        array $context,
        CarbonImmutable $createdAt,
    ): void {
        PageView::create([
            'analytics_event_id' => $event->id,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'url' => $properties['url'] ?? null,
            'referrer' => $context['referrer'] ?? null,
            'source' => $this->resolveSource($context['referrer'] ?? null, $context['utm_source'] ?? null),
            'created_at' => $createdAt,
        ]);

        TrafficSource::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'user_id' => $userId,
                'source' => $this->resolveSource($context['referrer'] ?? null, $context['utm_source'] ?? null),
                'medium' => $context['utm_medium'] ?? null,
                'campaign' => $context['utm_campaign'] ?? null,
                'referrer_url' => $context['referrer'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'created_at' => $createdAt,
            ],
        );
    }

    private function createProductView(
        AnalyticsEvent $event,
        string $sessionId,
        ?int $userId,
        array $properties,
        CarbonImmutable $createdAt,
    ): void {
        ProductView::create([
            'analytics_event_id' => $event->id,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'product_id' => $properties['product_id'],
            'created_at' => $createdAt,
        ]);
    }

    private function createCartEvent(
        AnalyticsEvent $event,
        string $eventType,
        string $sessionId,
        ?int $userId,
        array $properties,
        CarbonImmutable $createdAt,
    ): void {
        CartEvent::create([
            'analytics_event_id' => $event->id,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'cart_id' => $properties['cartId'] ?? null,
            'product_id' => $properties['productId'] ?? null,
            'event_type' => $eventType,
            'quantity' => $properties['quantity'] ?? null,
            'price' => $properties['price'] ?? null,
            'created_at' => $createdAt,
        ]);
    }

    private function createOrderEvent(
        AnalyticsEvent $event,
        string $eventType,
        string $sessionId,
        ?int $userId,
        array $properties,
        CarbonImmutable $createdAt,
    ): void {
        OrderEvent::create([
            'analytics_event_id' => $event->id,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'order_id' => $properties['orderId'] ?? null,
            'event_type' => $eventType,
            'amount' => $properties['amount'] ?? null,
            'metadata' => ($properties['metadata'] ?? []) ?: null,
            'created_at' => $createdAt,
        ]);
    }
}
