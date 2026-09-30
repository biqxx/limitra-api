<?php

namespace App\Services\Sourcing;

use App\Exceptions\SupplierServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SupplierServiceClient
{
    public function search(string $supplier, string $query, int $page = 1): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('search', [
            'supplier' => $supplier,
            'q' => $query,
            'page' => $page,
        ]));
    }

    public function import(string $supplier, string $externalProductId): array
    {
        $endpoint = sprintf(
            'import/%s/%s',
            rawurlencode($supplier),
            rawurlencode($externalProductId),
        );

        return $this->send(fn (PendingRequest $http) => $http->post($endpoint));
    }

    private function send(callable $request): array
    {
        try {
            $response = $request($this->http());
        } catch (ConnectionException) {
            throw new SupplierServiceException('The supplier service is unavailable.', 503);
        }

        if (! $response->successful()) {
            throw $this->failedResponse($response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new SupplierServiceException('The supplier service returned an invalid response.');
        }

        return is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    }

    private function http(): PendingRequest
    {
        $baseUrl = rtrim((string) config('services.supplier.base_url'), '/');
        $secret = (string) config('services.supplier.secret');

        if ($baseUrl === '' || $secret === '') {
            throw new SupplierServiceException('The supplier service is not configured.', 503);
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withHeaders(['X-Internal-Service-Secret' => $secret])
            ->timeout((int) config('services.supplier.timeout', 20))
            ->retry((int) config('services.supplier.retries', 2), 200, throw: false);
    }

    private function failedResponse(Response $response): SupplierServiceException
    {
        return match (true) {
            $response->status() === 404 => new SupplierServiceException('The supplier product was not found.', 404),
            $response->clientError() => new SupplierServiceException('The supplier rejected the request.', 422),
            default => new SupplierServiceException('The supplier service could not complete the request.'),
        };
    }
}
