<?php

namespace App\Console\Commands;

use App\Models\Order\Shipment;
use App\Services\Logistics\RedStarService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncRedStarShipmentsCommand extends Command
{
    protected $signature = 'redstar:sync-shipments';

    protected $description = 'Sync active Red Star Logistics shipment statuses and tracking events';

    public function handle(RedStarService $redStar): int
    {
        $shipments = Shipment::query()
            ->where('courier', 'redstar')
            ->whereIn('status', ['shipped', 'in_transit'])
            ->whereNotNull('tracking_number')
            ->with('order')
            ->get();

        if ($shipments->isEmpty()) {
            $this->info('No active Red Star shipments to sync.');

            return self::SUCCESS;
        }

        $synced = 0;

        foreach ($shipments as $shipment) {
            try {
                $tracking = $redStar->trackShipment($shipment->tracking_number);
                $logs = $tracking['shipmentLogs'] ?? $tracking['logs'] ?? [];

                if (is_array($logs)) {
                    foreach ($logs as $log) {
                        if (! is_array($log)) {
                            continue;
                        }

                        $description = (string) ($log['log'] ?? $log['status'] ?? '');
                        if ($description === '') {
                            continue;
                        }

                        $occurredAt = $log['createdOn'] ?? $log['date'] ?? now()->toDateTimeString();
                        $location = $log['station'] ?? $log['location'] ?? null;

                        $exists = $shipment->events()
                            ->where('description', $description)
                            ->where('location', $location)
                            ->exists();

                        if (! $exists) {
                            $shipment->events()->create([
                                'status' => $shipment->status,
                                'description' => $description,
                                'location' => $location,
                                'occurred_at' => $occurredAt,
                                'source' => 'redstar',
                            ]);
                        }
                    }
                }

                $synced++;
            } catch (Throwable $exception) {
                Log::warning('Red Star shipment sync failed', [
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Synced {$synced} Red Star shipment(s).");

        return self::SUCCESS;
    }
}

