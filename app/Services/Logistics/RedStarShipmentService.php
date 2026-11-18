<?php

namespace App\Services\Logistics;

use App\Models\Order\Order;
use App\Models\Order\Shipment;
use App\Services\Order\OrderLifecycleService;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RedStarShipmentService
{
    public function __construct(
        private readonly RedStarService $redStar,
        private readonly OrderLifecycleService $lifecycle,
    ) {}

    /**
     * Dispatch an order to Red Star Logistics and record the resulting Shipment.
     *
     * @param  array<string, mixed>  $options
     */
    public function dispatch(Order $order, array $options, int $actorId): Order
    {
        $order->loadMissing(['items.product.sources', 'shippingAddress']);

        if (! in_array($order->status, ['confirmed', 'processing'], true)) {
            throw ValidationException::withMessages([
                'order' => ['Only confirmed or processing orders can be shipped.'],
            ]);
        }

        if ($order->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'order' => ['Online-payment orders must be paid before shipping.'],
            ]);
        }

        if ($order->shipment()->exists()) {
            throw ValidationException::withMessages([
                'order' => ['This order already has a shipment.'],
            ]);
        }

        $address = $order->shipping_address ?? [];
        $recipientCity = $address['redstar_city_code'] ?? $order->shippingAddress?->redstar_city_code;
        $recipientTownId = $address['redstar_town_id'] ?? $order->shippingAddress?->redstar_town_id;

        if (! $recipientCity || ! $recipientTownId) {
            throw ValidationException::withMessages([
                'shipping_address' => ['Order shipping address is missing Red Star city/town mapping.'],
            ]);
        }

        $totalWeight = $this->orderWeightKg($order);
        $totalPieces = max(1, (int) $order->items->sum('quantity'));

        $payload = [
            'senderCity' => (string) config('services.redstar.sender_city', 'LOS'),
            'senderTownID' => (int) config('services.redstar.sender_town_id', 1),
            'senderName' => (string) config('services.redstar.sender_name', 'Limitra'),
            'senderAddress' => (string) config('services.redstar.sender_address', ''),
            'senderPhone' => (string) config('services.redstar.sender_phone', ''),
            'recipientCity' => (string) $recipientCity,
            'recipientTownID' => (int) $recipientTownId,
            'recipientName' => (string) ($address['recipient_name'] ?? 'Customer'),
            'recipientPhoneNo' => (string) ($address['phone'] ?? ''),
            'recipientEmail' => (string) $order->contact_email,
            'recipientAddress' => trim(implode(', ', array_filter([
                $address['line1'] ?? null,
                $address['line2'] ?? null,
            ]))),
            'recipientState' => (string) ($address['state'] ?? ''),
            'orderNo' => (string) $order->number,
            'packaging' => (string) ($options['packaging'] ?? 'Box'),
            'boxandCrating' => (string) ($options['box_and_crating'] ?? 'No'),
            'deliveryType' => (string) ($options['delivery_type'] ?? config('services.redstar.default_delivery_type', 'Normal')),
            'description' => (string) ($options['description'] ?? "Limitra Order {$order->number}"),
            'onforwardingLocation' => (string) ($options['onforwarding_location'] ?? ($address['city'] ?? '')),
            'paymentType' => (string) config('services.redstar.default_payment_type', 'Prepaid'),
            'pickupType' => (int) ($options['pickup_type'] ?? config('services.redstar.default_pickup_type', 1)),
            'weight' => $totalWeight,
            'pieces' => $totalPieces,
            'cashOnDelivery' => 0,
            'shipmentItems' => $order->items->map(fn ($item): array => [
                'commodity' => (string) $item->product_name,
                'description' => (string) $item->product_name,
                'countryOfManufacturing' => 'US',
                'quantity' => (int) $item->quantity,
                'weight' => $this->itemWeightKg($item->product),
                'unitOfMeasure' => 1,
                'unitOfPrice' => (float) $item->unit_price,
            ])->values()->all(),
        ];

        $response = $this->redStar->createPickupRequest($payload);
        $waybill = $this->extractWaybill($response);

        $shippedOrder = $this->lifecycle->ship($order, [
            'courier' => 'redstar',
            'tracking_number' => $waybill,
            'estimated_delivery_at' => $options['estimated_delivery_at'] ?? null,
            'location' => $options['location'] ?? (string) config('services.redstar.sender_city', 'LOS'),
            'note' => $options['note'] ?? 'Dispatched to Red Star Logistics.',
        ], $actorId);

        $shippedOrder->shipment?->update(['metadata' => $response]);

        return $shippedOrder->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function waybill(Order $order, bool $mini = false): array
    {
        $shipment = $this->requireRedStarShipment($order);

        return $this->redStar->printWaybill($shipment->tracking_number, $mini);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(Order $order): array
    {
        $shipment = $this->requireRedStarShipment($order);
        $result = $this->redStar->cancelShipment($shipment->tracking_number);

        $shipment->update([
            'status' => 'cancelled',
            'metadata' => array_merge($shipment->metadata ?? [], ['cancel_response' => $result]),
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function track(Order $order): array
    {
        $shipment = $this->requireRedStarShipment($order);

        return $this->redStar->trackShipment($shipment->tracking_number);
    }

    private function requireRedStarShipment(Order $order): Shipment
    {
        $shipment = $order->shipment;

        if (! $shipment || $shipment->courier !== 'redstar' || ! $shipment->tracking_number) {
            throw ValidationException::withMessages([
                'shipment' => ['This order does not have a Red Star Logistics shipment.'],
            ]);
        }

        return $shipment;
    }

    private function orderWeightKg(Order $order): float
    {
        $total = 0.0;

        foreach ($order->items as $item) {
            $total += $this->itemWeightKg($item->product) * $item->quantity;
        }

        return max(0.1, round($total, 2));
    }

    private function itemWeightKg(mixed $product): float
    {
        if ($product && $product->weight_kg !== null && (float) $product->weight_kg > 0) {
            return (float) $product->weight_kg;
        }

        if ($product && $product->relationLoaded('sources')) {
            $lb = $product->sources->first()?->weight_lb;
            if ($lb !== null && (float) $lb > 0) {
                return round((float) $lb * 0.453592, 3);
            }
        }

        return (float) config('services.redstar.default_weight_kg', 1.0);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractWaybill(array $response): string
    {
        foreach (['waybillNumber', 'waybillNo', 'waybill_number', 'waybill', 'tracking_number'] as $key) {
            if (! empty($response[$key]) && is_string($response[$key])) {
                return $response[$key];
            }
        }

        if (isset($response['data']) && is_array($response['data'])) {
            return $this->extractWaybill($response['data']);
        }

        throw new RuntimeException('Red Star Logistics API did not return a waybill number.');
    }
}

