<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'quote_id' => $this->quote_id,
            'expires_at' => $this->expires_at,
            'currency' => $this->currency,
            'lines' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'name' => $item->product_name,
                'sku' => $item->sku,
                'selected_options' => $item->selected_options ?? [],
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ])),
            'subtotal' => $this->subtotal,
            'discounts' => $this->promotion_snapshot ? [[
                'type' => 'promotion',
                'code' => $this->promotion_snapshot['code'],
                'amount' => $this->discount_total,
                'free_shipping' => $this->promotion_snapshot['free_shipping'],
            ]] : [],
            'shipping' => $this->shipping_snapshot,
            'wallet_credit' => $this->wallet_credit,
            'grand_total' => $this->grand_total,
            'payment_method' => $this->payment_method,
            'warnings' => $this->warnings ?? [],
        ];
    }
}
