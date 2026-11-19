<?php

namespace App\Services\Logistics;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RedStarService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function cities(): array
    {
        return Cache::remember(
            'redstar:cities',
            $this->locationCacheTtl(),
            fn (): array => $this->get('/api/Operations/Cities'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deliveryTowns(string $cityCode): array
    {
        $code = strtoupper(trim($cityCode));

        return Cache::remember(
            "redstar:towns:{$code}",
            $this->locationCacheTtl(),
            fn (): array => $this->get('/api/Operations/DeliveryTowns/'.rawurlencode($code)),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dropOffLocations(string $cityCode): array
    {
        $code = strtoupper(trim($cityCode));

        return Cache::remember(
            "redstar:dropoffs:{$code}",
            $this->locationCacheTtl(),
            fn (): array => $this->get('/api/Operations/DropOffLocations/'.rawurlencode($code)),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pickupTypes(): array
    {
        return Cache::remember(
            'redstar:pickup_types',
            $this->locationCacheTtl(),
            fn (): array => $this->get('/api/Operations/PickupTypes'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paymentTypes(): array
    {
        return Cache::remember(
            'redstar:payment_types',
            $this->locationCacheTtl(),
            fn (): array => $this->get('/api/Operations/PaymentTypes'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function shipmentStatuses(): array
    {
        return Cache::remember(
            'redstar:shipment_statuses',
            $this->locationCacheTtl(),
            fn (): array => $this->get('/api/Operations/ShipmentStatus'),
        );
    }

    /**
     * Resolve and validate a Red Star city code + town ID against cached Red Star locations.
     *
     * @return array{city_code: string, city_name: string, town_id: int, town_name: string}|null
     */
    public function resolveLocation(string $cityCode, int $townId): ?array
    {
        $normalizedCode = strtoupper(trim($cityCode));
        $city = collect($this->cities())->first(
            fn (array $item): bool => strtoupper((string) ($item['abbr'] ?? '')) === $normalizedCode
        );

        if (! $city) {
            return null;
        }

        $town = collect($this->deliveryTowns($normalizedCode))->first(
            fn (array $item): bool => (int) ($item['id'] ?? 0) === $townId
        );

        if (! $town) {
            return null;
        }

        return [
            'city_code' => $normalizedCode,
            'city_name' => (string) ($city['name'] ?? $normalizedCode),
            'town_id' => $townId,
            'town_name' => (string) ($town['name'] ?? ''),
        ];
    }

    /**
     * Calculate and cache delivery fee for a route + weight bucket.
     *
     * @return array{fee: float, raw: array<string, mixed>}
     */
    public function calculateDeliveryFee(
        string $recipientCityCode,
        int $recipientTownId,
        float $weightKg,
        ?int $pickupType = null,
        ?string $senderCityCode = null,
        ?int $senderTownId = null,
    ): array {
        $senderCityCode = strtoupper(trim($senderCityCode ?: (string) config('services.redstar.sender_city', 'MLD')));
        $senderTown = $senderTownId ?: (int) config('services.redstar.sender_town_id', 3164);
        $recipientCityCode = strtoupper(trim($recipientCityCode));
        $senderCity = $this->resolveCityName($senderCityCode);
        $recipientCity = $this->resolveCityName($recipientCityCode);
        $pickup = $pickupType ?: (int) config('services.redstar.default_pickup_type', 1);
        $normalizedWeight = max(0.1, round($weightKg, 2));

        $cacheKey = implode(':', [
            'redstar',
            'fee',
            md5($senderCity),
            $senderTown,
            md5($recipientCity),
            $recipientTownId,
            $pickup,
            number_format($normalizedWeight, 2, '.', ''),
        ]);

        return Cache::remember(
            $cacheKey,
            $this->feeCacheTtl(),
            function () use ($senderCity, $senderTown, $recipientCity, $recipientTownId, $pickup, $normalizedWeight): array {
                $response = $this->post('/api/Operations/DeliveryFee', [
                    'senderCity' => $senderCity,
                    'recipientCity' => $recipientCity,
                    'recipientTownID' => $recipientTownId,
                    'senderTownID' => $senderTown,
                    'pickupType' => $pickup,
                    'weight' => $normalizedWeight,
                ]);

                $fee = $this->extractFee($response);

                return [
                    'fee' => $fee,
                    'raw' => $response,
                ];
            },
        );
    }

    private function resolveCityName(string $cityCodeOrName): string
    {
        $needle = strtoupper(trim($cityCodeOrName));
        if ($needle === 'LOS') {
            $needle = 'MLD';
        }

        $match = collect($this->cities())->first(function (array $item) use ($needle): bool {
            return strtoupper((string) ($item['abbr'] ?? '')) === $needle
                || strtoupper((string) ($item['name'] ?? '')) === $needle;
        });

        return (string) ($match['name'] ?? $cityCodeOrName);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPickupRequest(array $payload): array
    {
        return $this->post('/api/Operations/PickupRequest', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function trackShipment(string $waybillNo): array
    {
        $normalized = trim($waybillNo);

        return Cache::remember(
            'redstar:track:'.sha1($normalized),
            60,
            fn (): array => $this->get('/api/Operations/TrackShipment', ['Waybillno' => $normalized]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function printWaybill(string $waybillNo, bool $mini = false): array
    {
        $endpoint = $mini ? '/api/Operations/PrintMiniWaybill' : '/api/Operations/PrintWaybill';

        return $this->get($endpoint, ['Waybillno' => trim($waybillNo)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelShipment(string $waybillNumber): array
    {
        return $this->get('/api/Operations/CancelShipment', ['WaybillNumber' => trim($waybillNumber)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function merchantShipmentStatus(string $dateTimeFrom, string $dateTimeTo): array
    {
        return $this->get('/api/Operations/MerchantShipmentStatus', [
            'DateTimeFrom' => $dateTimeFrom,
            'DateTimeTo' => $dateTimeTo,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private function get(string $endpoint, array $query = []): array
    {
        try {
            $response = $this->client()->get($endpoint, $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Red Star Logistics API.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Red Star Logistics API returned an error: '.$response->status());
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $payload): array
    {
        try {
            $response = $this->client()->post($endpoint, $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach Red Star Logistics API.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Red Star Logistics API returned an error: '.$response->status());
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    private function client(): PendingRequest
    {
        $headers = [];
        $apiKey = config('services.redstar.api_key');

        if (is_string($apiKey) && $apiKey !== '') {
            $headers['X-API-KEY'] = $apiKey;
        }

        return Http::baseUrl((string) config('services.redstar.base_url'))
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.redstar.timeout', 15));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractFee(array $response): float
    {
        if (isset($response['value']) && is_numeric($response['value'])) {
            return round((float) $response['value'], 2);
        }

        $lower = array_change_key_case($response, CASE_LOWER);
        foreach (['totalamount', 'total_amount', 'deliveryfee', 'delivery_fee', 'fee', 'amount', 'price'] as $key) {
            if (isset($lower[$key]) && is_numeric($lower[$key])) {
                return round((float) $lower[$key], 2);
            }
        }

        if (isset($lower['data']) && is_array($lower['data'])) {
            return $this->extractFee($lower['data']);
        }

        throw new RuntimeException('Red Star Logistics API did not return a valid delivery fee.');
    }

    private function locationCacheTtl(): int
    {
        return max(60, (int) config('services.redstar.location_cache_ttl', 86400));
    }

    private function feeCacheTtl(): int
    {
        return max(60, (int) config('services.redstar.fee_cache_ttl', 3600));
    }
}

