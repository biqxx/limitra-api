<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPriceQuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'supplier_price' => $this->supplier_price,
            'supplier_currency' => $this->supplier_currency,
            'weight_lb' => $this->weight_lb,
            'delivery_days_min' => $this->delivery_days_min,
            'delivery_days_max' => $this->delivery_days_max,
            'proposed_price_ngn' => $this->proposed_price_ngn,
            'approved_price_ngn' => $this->approved_price_ngn,
            'cost_breakdown' => $this->cost_breakdown ?? [],
            'rules_snapshot' => $this->rules_snapshot,
            'rules_version' => $this->rules_version,
            'missing_inputs' => $this->missing_inputs ?? [],
            'blocked_reasons' => $this->blocked_reasons ?? [],
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'quoted_at' => $this->quoted_at,
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
        ];
    }
}
