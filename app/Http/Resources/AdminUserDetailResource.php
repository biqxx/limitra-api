<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => new AdminUserResource($this->resource['user']),
            'summary' => $this->resource['summary'],
            'recent_orders' => AdminUserOrderSummaryResource::collection($this->resource['recent_orders']),
            'recent_activity' => AdminUserActivityResource::collection($this->resource['recent_activity']),
        ];
    }
}
