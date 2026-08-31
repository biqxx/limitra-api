<?php

namespace App\Jobs;

use App\Services\Analytics\AnalyticsTrackingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecordAnalyticsEvent implements ShouldQueue
{
    use Queueable;

    public const PAGE_VIEW = 'page_view';

    public const PRODUCT_VIEW = 'product_view';

    public const CART_EVENT = 'cart_event';

    public const ORDER_EVENT = 'order_event';

    public int $tries = 3;

    public array $backoff = [1, 5, 30];

    public readonly string $eventId;

    public readonly string $occurredAt;

    public function __construct(
        public readonly string $kind,
        public readonly string $eventType,
        public readonly string $sessionId,
        public readonly ?int $userId,
        public readonly array $properties = [],
        public readonly array $context = [],
        ?string $eventId = null,
        ?string $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? now()->toISOString();
        $this->onQueue('analytics');
    }

    public static function pageView(Request $request, string $sessionId, ?int $userId): self
    {
        return new self(
            self::PAGE_VIEW,
            'page_view',
            $sessionId,
            $userId,
            ['url' => self::limit($request->fullUrl(), 500)],
            self::requestContext($request),
        );
    }

    public static function productView(
        Request $request,
        string $sessionId,
        ?int $userId,
        int $productId,
    ): self {
        return new self(
            self::PRODUCT_VIEW,
            'product_view',
            $sessionId,
            $userId,
            ['product_id' => $productId],
            self::requestContext($request),
        );
    }

    public static function cartEvent(
        Request $request,
        string $eventType,
        string $sessionId,
        ?int $userId,
        ?int $cartId = null,
        ?int $productId = null,
        ?int $quantity = null,
        ?float $price = null,
    ): self {
        return new self(
            self::CART_EVENT,
            $eventType,
            $sessionId,
            $userId,
            compact('cartId', 'productId', 'quantity', 'price'),
            self::requestContext($request),
        );
    }

    public static function orderEvent(
        Request $request,
        string $eventType,
        string $sessionId,
        ?int $userId,
        ?int $orderId = null,
        ?float $amount = null,
        array $metadata = [],
    ): self {
        return new self(
            self::ORDER_EVENT,
            $eventType,
            $sessionId,
            $userId,
            compact('orderId', 'amount', 'metadata'),
            self::requestContext($request),
        );
    }

    public function handle(AnalyticsTrackingService $tracking): void
    {
        $tracking->recordQueuedEvent(
            $this->eventId,
            $this->kind,
            $this->eventType,
            $this->sessionId,
            $this->userId,
            $this->properties,
            $this->context,
            $this->occurredAt,
        );
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }

    private static function requestContext(Request $request): array
    {
        return [
            'ip_address' => self::limit($request->ip(), 45),
            'user_agent' => self::limit($request->userAgent(), 1000),
            'referrer' => self::limit($request->header('Referer'), 500),
            'utm_source' => self::limit($request->query('utm_source'), 100),
            'utm_medium' => self::limit($request->query('utm_medium'), 100),
            'utm_campaign' => self::limit($request->query('utm_campaign'), 255),
        ];
    }

    private static function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
