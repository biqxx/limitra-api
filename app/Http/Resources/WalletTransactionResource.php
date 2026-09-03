<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'balance_type' => $this->balance_type,
            'direction' => $this->direction,
            'amount' => number_format($this->amount_minor / 100, 2, '.', ''),
            'amount_minor' => $this->amount_minor,
            'balance_after' => number_format($this->balance_after_minor / 100, 2, '.', ''),
            'balance_after_minor' => $this->balance_after_minor,
            'currency' => $this->currency,
            'status' => $this->status,
            'description' => $this->description,
            'source' => $this->source_type ? [
                'type' => $this->source_type,
                'id' => $this->source_id,
            ] : null,
            'reversal_of' => $this->reverses_transaction_id,
            'created_at' => $this->created_at,
        ];
    }
}
