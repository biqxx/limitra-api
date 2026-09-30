<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\SupplierServiceException;
use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\ImportSupplierProductRequest;
use App\Http\Requests\Admin\SearchSupplierProductsRequest;
use App\Http\Resources\ProductSourceResource;
use App\Models\Product\ProductSource;
use App\Services\Sourcing\SupplierProductImportService;
use App\Services\Sourcing\SupplierServiceClient;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use UnexpectedValueException;

class SourcingController extends BaseController
{
    public function sources(): JsonResponse
    {
        $configured = filled(config('services.supplier.base_url'))
            && filled(config('services.supplier.secret'));

        return $this->success([
            ['id' => 'amazon', 'name' => 'Amazon', 'enabled' => $configured],
            ['id' => 'ebay', 'name' => 'eBay', 'enabled' => $configured],
            ['id' => 'shein', 'name' => 'Shein', 'enabled' => false, 'reason' => 'Not supported by the supplier service yet.'],
        ]);
    }

    public function search(SearchSupplierProductsRequest $request, SupplierServiceClient $client): JsonResponse
    {
        try {
            $payload = $client->search(
                $request->validated('source'),
                $request->validated('q'),
                $request->integer('page', 1),
            );
        } catch (SupplierServiceException $exception) {
            return $this->error($exception->getMessage(), $exception->httpStatus);
        }

        $products = collect($payload['products'] ?? []);
        $externalIds = $products->map(fn (array $product) => (string) ($product['supplier_id'] ?? $product['id'] ?? ''))
            ->filter()
            ->unique()
            ->values();
        $imports = ProductSource::query()
            ->whereIn('external_product_id', $externalIds)
            ->get()
            ->keyBy(fn (ProductSource $source) => $source->supplier.':'.$source->external_product_id);

        $payload['products'] = $products->map(function (array $product) use ($imports, $request) {
            $supplier = strtolower((string) ($product['supplier'] ?? $request->validated('source')));
            $externalId = (string) ($product['supplier_id'] ?? $product['id'] ?? '');
            $import = $imports->get($supplier.':'.$externalId);

            return array_merge($product, [
                'source' => $supplier,
                'external_product_id' => $externalId,
                'imported' => $import !== null,
                'product_id' => $import?->product_id,
            ]);
        })->values()->all();

        return $this->success($payload);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $sources = ProductSource::query()
            ->with(['product.category', 'product.subcategory', 'product.productImages'])
            ->latest('id')
            ->paginate($perPage);

        return $this->success([
            'items' => ProductSourceResource::collection($sources->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $sources->currentPage(),
                'last_page' => $sources->lastPage(),
                'per_page' => $sources->perPage(),
                'total' => $sources->total(),
            ],
        ]);
    }

    public function store(
        ImportSupplierProductRequest $request,
        SupplierProductImportService $importer,
    ): JsonResponse {
        try {
            $source = $importer->import($request->validated(), $request->user());
        } catch (SupplierServiceException $exception) {
            return $this->error($exception->getMessage(), $exception->httpStatus);
        } catch (DomainException $exception) {
            return $this->error($exception->getMessage(), 409);
        } catch (UnexpectedValueException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success(
            (new ProductSourceResource($source))->resolve($request),
            'Supplier product imported as a draft.',
            201,
        );
    }
}
