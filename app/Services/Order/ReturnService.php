<?php

namespace App\Services\Order;

use App\Models\Order\Order;
use App\Models\Order\ReturnItem;
use App\Models\Order\ReturnRequest;
use App\Services\Settings\BusinessSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReturnService
{
    public function __construct(private readonly BusinessSettingsService $settings) {}

    public function create(Order $order, array $data, int $userId, array $images = []): ReturnRequest
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($order, $data, $userId, $images, &$storedPaths) {
                $order = Order::with('shipment')->whereKey($order->id)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
                if ($order->fulfilment_status !== 'delivered') {
                    $this->invalid('order', 'Only delivered orders can be returned.');
                }

                $windowDays = (int) $this->settings->value('returns.window_days');
                $deliveredAt = $this->deliveredAt($order);
                if ($windowDays <= 0 || now()->greaterThan($deliveredAt->copy()->addDays($windowDays))) {
                    $this->invalid('order', 'The return window for this order has closed.');
                }

                $itemData = collect($data['items'])->keyBy('order_item_id');
                $orderItems = $order->items()->whereIn('id', $itemData->keys())->orderBy('id')->lockForUpdate()->get();
                if ($orderItems->count() !== $itemData->count()) {
                    $this->invalid('items', 'One or more selected items do not belong to this order.');
                }

                $requestedTotalMinor = 0;
                foreach ($orderItems as $orderItem) {
                    $requested = (int) $itemData[$orderItem->id]['quantity'];
                    $alreadyRequested = (int) ReturnItem::query()
                        ->where('order_item_id', $orderItem->id)
                        ->whereHas('returnRequest', fn ($query) => $query->whereNotIn('status', ['rejected', 'cancelled']))
                        ->sum('quantity');
                    if ($requested + $alreadyRequested > $orderItem->quantity) {
                        $this->invalid('items', "The return quantity for {$orderItem->product_name} exceeds the purchased quantity.");
                    }
                    $requestedTotalMinor += $this->toMinorUnits($orderItem->unit_price) * $requested;
                }

                $returnRequest = ReturnRequest::create([
                    'number' => 'LMT-RET-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
                    'order_id' => $order->id,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'resolution' => $data['resolution'],
                    'currency' => $order->currency,
                    'requested_total' => $this->fromMinorUnits($requestedTotalMinor),
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($orderItems as $orderItem) {
                    $item = $itemData[$orderItem->id];
                    $quantity = (int) $item['quantity'];
                    $amountMinor = $this->toMinorUnits($orderItem->unit_price) * $quantity;
                    $returnRequest->items()->create([
                        'order_item_id' => $orderItem->id,
                        'quantity' => $quantity,
                        'reason' => $item['reason'],
                        'notes' => $item['notes'] ?? null,
                        'unit_price' => $orderItem->unit_price,
                        'requested_amount' => $this->fromMinorUnits($amountMinor),
                    ]);
                }

                foreach ($images as $position => $image) {
                    $path = $image->store("returns/{$returnRequest->id}", 'public');
                    if (! $path) {
                        throw new \RuntimeException('The return image could not be stored.');
                    }
                    $storedPaths[] = $path;
                    $returnRequest->images()->create(['path' => $path, 'sort_order' => $position]);
                }

                $returnRequest->events()->create([
                    'to_status' => 'pending',
                    'source' => 'customer',
                    'actor_id' => $userId,
                    'note' => 'Return request submitted for approval.',
                    'created_at' => now(),
                ]);

                return $returnRequest->load($this->relations());
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }
    }

    public function cancel(ReturnRequest $returnRequest, int $userId): ReturnRequest
    {
        return DB::transaction(function () use ($returnRequest, $userId) {
            $returnRequest = ReturnRequest::whereKey($returnRequest->id)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if ($returnRequest->status !== 'pending') {
                $this->invalid('return', 'Only a pending return request can be cancelled.');
            }

            $returnRequest->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->event($returnRequest, 'pending', 'cancelled', 'customer', $userId, 'Return request cancelled.');

            return $returnRequest->load($this->relations());
        }, 3);
    }

    public function transition(ReturnRequest $returnRequest, array $data, int $actorId): ReturnRequest
    {
        return DB::transaction(function () use ($returnRequest, $data, $actorId) {
            $returnRequest = ReturnRequest::whereKey($returnRequest->id)->lockForUpdate()->firstOrFail();
            $from = $returnRequest->status;
            $to = $data['status'];

            if ($to === 'approved') {
                if (! in_array($from, ['pending', 'approved'], true)) {
                    $this->invalid('status', "A {$from} return cannot be approved.");
                }
                $this->approve($returnRequest, $data['items'], $actorId, $data['notes'] ?? null);
            } elseif ($to === 'rejected') {
                if (! in_array($from, ['pending', 'approved'], true)) {
                    $this->invalid('status', "A {$from} return cannot be rejected.");
                }
                if ($returnRequest->refunds()->whereNotIn('status', ['failed'])->exists()) {
                    $this->invalid('status', 'A return with an active refund cannot be rejected.');
                }
                $returnRequest->update([
                    'status' => 'rejected',
                    'rejection_reason' => $data['reason'],
                    'admin_notes' => $data['notes'] ?? $returnRequest->admin_notes,
                ]);
            } elseif ($to === 'received') {
                if ($from !== 'approved') {
                    $this->invalid('status', 'Only an approved return can be marked as received.');
                }
                $returnRequest->update(['status' => 'received', 'admin_notes' => $data['notes'] ?? $returnRequest->admin_notes]);
            } elseif ($to === 'completed') {
                if ($from !== 'received') {
                    $this->invalid('status', 'Only a received return can be completed.');
                }
                if ($returnRequest->resolution === 'refund') {
                    $processed = $returnRequest->refunds()->where('status', 'processed')->sum('amount');
                    if ($this->toMinorUnits($processed) < $this->toMinorUnits($returnRequest->approved_total)) {
                        $this->invalid('status', 'The approved refund has not finished processing.');
                    }
                }
                if ($returnRequest->resolution === 'store_credit') {
                    $this->invalid('status', 'Store-credit completion requires the wallet ledger workflow.');
                }
                $returnRequest->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'admin_notes' => $data['notes'] ?? $returnRequest->admin_notes,
                ]);
            }

            $this->event($returnRequest, $from, $to, 'staff', $actorId, $data['notes'] ?? $data['reason'] ?? null);

            return $returnRequest->load($this->relations());
        }, 3);
    }

    /** @return array<int, string> */
    public function relations(): array
    {
        return ['items.orderItem', 'images', 'events', 'refunds'];
    }

    private function approve(ReturnRequest $returnRequest, array $approvals, int $actorId, ?string $notes): void
    {
        $items = $returnRequest->items()->orderBy('id')->lockForUpdate()->get();
        $provided = collect($approvals)->keyBy('id');
        if ($provided->count() !== $items->count() || $items->pluck('id')->diff($provided->keys())->isNotEmpty()) {
            $this->invalid('items', 'Approved quantities must be supplied for every return item.');
        }

        $approvedTotalMinor = 0;
        foreach ($items as $item) {
            $quantity = (int) $provided[$item->id]['approved_quantity'];
            if ($quantity > $item->quantity) {
                $this->invalid('items', 'An approved quantity cannot exceed the requested quantity.');
            }
            $amountMinor = $this->toMinorUnits($item->unit_price) * $quantity;
            $approvedTotalMinor += $amountMinor;
            $item->update([
                'approved_quantity' => $quantity,
                'approved_amount' => $this->fromMinorUnits($amountMinor),
            ]);
        }
        if ($approvedTotalMinor === 0) {
            $this->invalid('items', 'At least one item quantity must be approved.');
        }
        $activeRefundMinor = (int) $returnRequest->refunds()->whereNotIn('status', ['failed'])->sum('amount_minor');
        if ($approvedTotalMinor < $activeRefundMinor) {
            $this->invalid('items', 'Approved quantities cannot be reduced below refunds already submitted.');
        }

        $returnRequest->update([
            'status' => 'approved',
            'approved_total' => $this->fromMinorUnits($approvedTotalMinor),
            'approved_by' => $actorId,
            'approved_at' => now(),
            'admin_notes' => $notes ?? $returnRequest->admin_notes,
            'rejection_reason' => null,
        ]);
    }

    private function deliveredAt(Order $order): Carbon
    {
        if ($order->shipment?->delivered_at) {
            return Carbon::parse($order->shipment->delivered_at);
        }

        $eventAt = $order->statusEvents()->where('to_status', 'delivered')->oldest('id')->value('created_at');

        return Carbon::parse($eventAt ?? $order->updated_at);
    }

    private function event(
        ReturnRequest $returnRequest,
        ?string $from,
        string $to,
        string $source,
        ?int $actorId,
        ?string $note,
    ): void {
        $returnRequest->events()->create([
            'from_status' => $from,
            'to_status' => $to,
            'source' => $source,
            'actor_id' => $actorId,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function toMinorUnits(mixed $amount): int
    {
        [$whole, $fraction] = explode('.', number_format((float) $amount, 2, '.', ''));

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function fromMinorUnits(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
