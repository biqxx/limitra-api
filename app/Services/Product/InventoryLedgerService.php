<?php

namespace App\Services\Product;

use App\Models\Product\InventoryAdjustment;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Models\User;
use App\Services\Admin\AuditEventService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryLedgerService
{
    public function __construct(private readonly AuditEventService $auditEvents) {}

    public function adjust(
        Product $product,
        ?ProductVariant $variant,
        int $delta,
        string $reason,
        ?string $notes,
        User $actor,
    ): InventoryAdjustment {
        if ($delta === 0) {
            throw ValidationException::withMessages([
                'quantity_delta' => ['The quantity delta cannot be zero.'],
            ]);
        }

        if ($variant && (int) $variant->product_id !== (int) $product->id) {
            throw ValidationException::withMessages([
                'variant_id' => ['Specified variant does not belong to this product.'],
            ]);
        }

        return DB::transaction(function () use ($product, $variant, $delta, $reason, $notes, $actor): InventoryAdjustment {
            if ($variant) {
                /** @var ProductVariant $target */
                $target = ProductVariant::whereKey($variant->id)->lockForUpdate()->firstOrFail();
                $before = (int) $target->stock;
                $after = $before + $delta;
                $threshold = (int) ($target->low_stock_threshold ?? $product->low_stock_threshold ?? 5);

                if ($after < 0) {
                    throw ValidationException::withMessages([
                        'quantity_delta' => ["Adjustment of {$delta} exceeds available variant stock of {$before}."],
                    ]);
                }

                $target->update(['stock' => $after]);
            } else {
                /** @var Product $target */
                $target = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                $before = (int) $target->stock;
                $after = $before + $delta;
                $threshold = (int) ($target->low_stock_threshold ?? 10);

                if ($after < 0) {
                    throw ValidationException::withMessages([
                        'quantity_delta' => ["Adjustment of {$delta} exceeds available product stock of {$before}."],
                    ]);
                }

                $target->update(['stock' => $after]);
            }

            $adjustment = InventoryAdjustment::query()->create([
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'adjusted_by' => $actor->id,
                'quantity_delta' => $delta,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => $reason,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $this->auditEvents->record(
                action: 'inventory.adjusted',
                actor: $actor,
                subject: $target,
                reason: $reason,
                before: ['stock' => $before],
                after: ['stock' => $after],
                metadata: [
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'quantity_delta' => $delta,
                    'notes' => $notes,
                ],
            );

            if ($after <= $threshold && $before > $threshold) {
                $this->auditEvents->record(
                    action: 'inventory.low_stock',
                    actor: $actor,
                    subject: $target,
                    reason: 'stock_threshold_breached',
                    before: ['stock' => $before, 'threshold' => $threshold],
                    after: ['stock' => $after, 'threshold' => $threshold],
                    metadata: [
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                        'current_stock' => $after,
                        'threshold' => $threshold,
                    ],
                );
            }

            return $adjustment;
        });
    }
}
