<?php

namespace App\Services\Sourcing;

use App\Models\Product\Product;
use Illuminate\Validation\ValidationException;

class ProductPublicationGuard
{
    public function assertCanActivate(Product $product): void
    {
        $hasUnapprovedSource = $product->sources()
            ->where(function ($query): void {
                $query->where('pricing_status', '!=', 'approved')
                    ->orWhereNull('approved_price_quote_id')
                    ->orWhereNull('final_price_ngn')
                    ->orWhere('final_price_ngn', '<=', 0);
            })
            ->exists();

        if ($hasUnapprovedSource) {
            throw ValidationException::withMessages([
                'status' => ['Approve the latest complete supplier price before activating this product.'],
            ]);
        }
    }

    public function assertPriceIsManaged(Product $product, mixed $requestedPrice): void
    {
        if (! $product->sources()->exists()) {
            return;
        }

        if (round((float) $requestedPrice, 2) !== round((float) $product->price, 2)) {
            throw ValidationException::withMessages([
                'price' => ['Recalculate and approve sourced-product prices through the pricing workflow.'],
            ]);
        }
    }
}
