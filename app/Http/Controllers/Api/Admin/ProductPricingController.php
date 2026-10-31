<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\ApproveProductPriceRequest;
use App\Http\Requests\Admin\RecalculateProductPriceRequest;
use App\Http\Resources\ProductPriceQuoteResource;
use App\Http\Resources\ProductSourceResource;
use App\Models\Product\ProductSource;
use App\Services\Sourcing\ProductPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductPricingController extends BaseController
{
    public function show(Request $request, ProductSource $productSource): JsonResponse
    {
        $productSource->load([
            'product.category',
            'product.subcategory',
            'currentPriceQuote',
            'approvedPriceQuote',
            'priceQuotes',
        ]);

        return $this->success([
            'source' => (new ProductSourceResource($productSource))->resolve($request),
            'quotes' => ProductPriceQuoteResource::collection($productSource->priceQuotes)->resolve($request),
        ]);
    }

    public function recalculate(
        RecalculateProductPriceRequest $request,
        ProductSource $productSource,
        ProductPricingService $pricing,
    ): JsonResponse {
        $source = $pricing->recalculate($productSource, $request->validated(), $request->user());

        return $this->success(
            (new ProductSourceResource($source))->resolve($request),
            $source->pricing_status === 'pending_approval'
                ? 'A new selling price was calculated and is ready for approval.'
                : 'Pricing inputs were saved. Complete the missing information before approval.',
        );
    }

    public function approve(
        ApproveProductPriceRequest $request,
        ProductSource $productSource,
        ProductPricingService $pricing,
    ): JsonResponse {
        $source = $pricing->approve(
            $productSource,
            $request->user(),
            $request->integer('quote_id') ?: null,
        );

        return $this->success(
            (new ProductSourceResource($source))->resolve($request),
            'Product price approved. The product can now be activated.',
        );
    }
}
