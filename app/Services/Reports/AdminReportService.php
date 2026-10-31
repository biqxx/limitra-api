<?php

namespace App\Services\Reports;

use App\Jobs\Reports\GenerateAdminReportJob;
use App\Models\Admin\AdminReport;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateCommission;
use App\Models\Affiliate\AffiliateWithdrawal;
use App\Models\Analytics\DailyAggregate;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\Admin\AuditEventService;
use App\Services\Reports\Formatters\CsvFormatter;
use App\Services\Reports\Formatters\XlsxFormatter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminReportService
{
    public function __construct(
        private readonly AuditEventService $audit,
        private readonly CsvFormatter $csvFormatter,
        private readonly XlsxFormatter $xlsxFormatter,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function requestReport(
        User $user,
        array $validated,
        ?string $idempotencyKey = null,
        ?Request $request = null,
    ): AdminReport {
        $type = (string) $validated['type'];
        $format = (string) ($validated['format'] ?? 'csv');
        $timezone = (string) ($validated['timezone'] ?? 'UTC');
        $limit = min((int) ($validated['limit'] ?? 50000), 50000);

        $requestHash = hash('sha256', (string) json_encode([
            'type' => $type,
            'format' => $format,
            'timezone' => $timezone,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'limit' => $limit,
            'columns' => $validated['columns'] ?? null,
            'filters' => $validated['filters'] ?? null,
        ]));

        if (! empty($idempotencyKey)) {
            $existing = AdminReport::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ($existing->request_hash === $requestHash) {
                    return $existing;
                }

                throw ValidationException::withMessages([
                    'idempotency_key' => ['Idempotency key collision with different report payload.'],
                ]);
            }
        }

        $randomSuffix = Str::lower(Str::random(12));
        $timestamp = now()->format('YmdHis');
        $fileName = "report-{$type}-{$randomSuffix}-{$timestamp}.{$format}";
        $filePath = "reports/{$fileName}";

        $report = AdminReport::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'format' => $format,
            'status' => AdminReport::STATUS_QUEUED,
            'parameters' => $validated,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'row_count' => 0,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'timezone' => $timezone,
            'columns' => $validated['columns'] ?? null,
            'filters' => $validated['filters'] ?? null,
            'expires_at' => now()->addDays(7),
        ]);

        $this->audit->record(
            action: 'report.requested',
            actor: $user,
            subject: $report,
            metadata: [
                'type' => $type,
                'format' => $format,
                'idempotency_key' => $idempotencyKey,
            ],
            request: $request,
        );

        GenerateAdminReportJob::dispatch($report->id);

        return $report;
    }

    public function generate(AdminReport $report): void
    {
        if ($report->status === AdminReport::STATUS_COMPLETED || $report->status === AdminReport::STATUS_EXPIRED) {
            return;
        }

        $report->update([
            'status' => AdminReport::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        try {
            $rows = $this->collectRows($report);
            $headers = $this->resolveHeaders($report, $rows);

            $content = match ($report->format) {
                'xlsx' => $this->xlsxFormatter->format($headers, $rows),
                default => $this->csvFormatter->format($headers, $rows),
            };

            Storage::disk('local')->put($report->file_path, $content);

            $report->update([
                'status' => AdminReport::STATUS_COMPLETED,
                'row_count' => count($rows),
                'file_size_bytes' => strlen($content),
                'completed_at' => now(),
                'expires_at' => now()->addDays(7),
                'error_message' => null,
            ]);

            $this->audit->record(
                action: 'report.completed',
                actor: $report->user,
                subject: $report,
                metadata: [
                    'row_count' => count($rows),
                    'file_size_bytes' => strlen($content),
                ],
            );
        } catch (Throwable $e) {
            Log::error('Report generation failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $report->update([
                'status' => AdminReport::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => 'Report generation encountered an error. Please retry or adjust parameters.',
            ]);

            $this->audit->record(
                action: 'report.failed',
                actor: $report->user,
                subject: $report,
                metadata: [
                    'type' => $report->type,
                ],
            );
        }
    }

    public function retry(AdminReport $report, User $actor, ?Request $request = null): AdminReport
    {
        if ($report->status === AdminReport::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => ['Completed reports cannot be retried.'],
            ]);
        }

        $report->update([
            'status' => AdminReport::STATUS_QUEUED,
            'retry_count' => $report->retry_count + 1,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ]);

        $this->audit->record(
            action: 'report.retried',
            actor: $actor,
            subject: $report,
            metadata: [
                'retry_count' => $report->retry_count,
            ],
            request: $request,
        );

        GenerateAdminReportJob::dispatch($report->id);

        return $report;
    }

    public function download(AdminReport $report, User $user, Request $request): StreamedResponse
    {
        if ($report->isExpired()) {
            $report->update(['status' => AdminReport::STATUS_EXPIRED]);
            abort(410, 'This report has expired.');
        }

        if (! $report->isCompleted() || empty($report->file_path)) {
            abort(409, 'Report is not ready for download.');
        }

        if (! Storage::disk('local')->exists($report->file_path)) {
            abort(404, 'Report file was not found.');
        }

        $this->audit->record(
            action: 'report.downloaded',
            actor: $user,
            subject: $report,
            metadata: [
                'file_name' => $report->file_name,
            ],
            request: $request,
        );

        $contentType = $report->format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8';

        return Storage::disk('local')->download($report->file_path, $report->file_name, [
            'Content-Type' => $contentType,
        ]);
    }

    public function pruneExpired(): int
    {
        $expiredReports = AdminReport::query()
            ->where(function ($query): void {
                $query->where('expires_at', '<=', now())
                    ->orWhere('status', AdminReport::STATUS_EXPIRED);
            })
            ->get();

        $prunedCount = 0;

        foreach ($expiredReports as $report) {
            if ($report->file_path && Storage::disk('local')->exists($report->file_path)) {
                Storage::disk('local')->delete($report->file_path);
            }

            $report->update([
                'status' => AdminReport::STATUS_EXPIRED,
                'file_path' => null,
            ]);

            $this->audit->record(
                action: 'report.expired',
                actor: null,
                subject: $report,
                metadata: [
                    'pruned_at' => now()->toIso8601String(),
                ],
            );

            $prunedCount++;
        }

        return $prunedCount;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectRows(AdminReport $report): array
    {
        $parameters = $report->parameters ?? [];
        $limit = min((int) ($parameters['limit'] ?? 50000), 50000);
        $tz = $report->timezone ?: 'UTC';
        $from = ! empty($parameters['date_from']) ? Carbon::parse($parameters['date_from'])->startOfDay() : null;
        $to = ! empty($parameters['date_to']) ? Carbon::parse($parameters['date_to'])->endOfDay() : null;
        $filters = $report->filters ?? [];

        return match ($report->type) {
            'orders' => $this->collectOrders($from, $to, $filters, $limit, $tz),
            'customers' => $this->collectCustomers($from, $to, $limit, $tz),
            'products' => $this->collectProducts($filters, $limit, $tz),
            'inventory' => $this->collectInventory($filters, $limit, $tz),
            'affiliates' => $this->collectAffiliates($filters, $limit, $tz),
            'commissions' => $this->collectCommissions($from, $to, $filters, $limit, $tz),
            'withdrawals' => $this->collectWithdrawals($from, $to, $filters, $limit, $tz),
            'revenue' => $this->collectRevenue($from, $to, $limit, $tz),
            'traffic' => $this->collectTraffic($from, $to, $limit, $tz),
            'conversion' => $this->collectConversion($from, $to, $limit, $tz),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectOrders(?Carbon $from, ?Carbon $to, array $filters, int $limit, string $tz): array
    {
        $query = Order::query()->with('user:id,email,username');

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['payment_status'])) {
            $query->where('payment_status', (string) $filters['payment_status']);
        }

        return $query->latest('id')->limit($limit)->get()->map(fn (Order $order) => [
            'id' => $order->id,
            'number' => $order->number,
            'customer_email' => $order->contact_email ?? $order->user?->email,
            'customer_name' => $order->user?->username ?? 'Guest',
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'currency' => $order->currency,
            'subtotal' => (string) $order->subtotal,
            'grand_total' => (string) $order->grand_total,
            'created_at' => $order->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectCustomers(?Carbon $from, ?Carbon $to, int $limit, string $tz): array
    {
        $query = User::query()->where('role', 'user');

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query->latest('id')->limit($limit)->get()->map(fn (User $user) => [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'created_at' => $user->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectProducts(array $filters, int $limit, string $tz): array
    {
        $query = Product::query()->with('category:id,name');

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        if (isset($filters['status'])) {
            $query->where('is_active', (bool) $filters['status']);
        }

        return $query->latest('id')->limit($limit)->get()->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'sku' => $p->sku,
            'category' => $p->category?->name,
            'price' => (string) $p->price,
            'compare_at_price' => (string) $p->compare_at_price,
            'stock' => $p->stock,
            'is_active' => $p->is_active ? 'yes' : 'no',
            'created_at' => $p->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectInventory(array $filters, int $limit, string $tz): array
    {
        $query = Product::query();

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['low_stock'])) {
            $query->where('stock', '<=', 5);
        }

        return $query->orderBy('stock')->limit($limit)->get()->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'stock' => $p->stock,
            'price' => (string) $p->price,
            'is_active' => $p->is_active ? 'yes' : 'no',
            'created_at' => $p->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectAffiliates(array $filters, int $limit, string $tz): array
    {
        $query = Affiliate::query()->with('user:id,email,username');

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->latest('id')->limit($limit)->get()->map(fn (Affiliate $affiliate) => [
            'id' => $affiliate->id,
            'user_id' => $affiliate->user_id,
            'code' => $affiliate->code ?? $affiliate->affiliate_code,
            'status' => $affiliate->status,
            'total_earnings' => (string) $affiliate->total_earnings,
            'pending_balance' => (string) $affiliate->pending_balance,
            'paid_balance' => (string) $affiliate->paid_balance,
            'created_at' => $affiliate->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectCommissions(?Carbon $from, ?Carbon $to, array $filters, int $limit, string $tz): array
    {
        $query = AffiliateCommission::query();

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['affiliate_id'])) {
            $query->where('affiliate_id', (int) $filters['affiliate_id']);
        }

        return $query->latest('id')->limit($limit)->get()->map(fn (AffiliateCommission $c) => [
            'id' => $c->id,
            'affiliate_id' => $c->affiliate_id,
            'affiliate_sale_id' => $c->affiliate_sale_id,
            'amount' => (string) ($c->commission_amount ?? $c->amount ?? 0),
            'status' => $c->payment_status ?? $c->status ?? 'pending',
            'created_at' => $c->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectWithdrawals(?Carbon $from, ?Carbon $to, array $filters, int $limit, string $tz): array
    {
        $query = AffiliateWithdrawal::query();

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['affiliate_id'])) {
            $query->where('affiliate_id', (int) $filters['affiliate_id']);
        }

        return $query->latest('id')->limit($limit)->get()->map(fn (AffiliateWithdrawal $w) => [
            'id' => $w->id,
            'affiliate_id' => $w->affiliate_id,
            'amount' => (string) $w->amount,
            'currency' => $w->currency,
            'status' => $w->status,
            'provider_reference' => $w->provider_reference,
            'created_at' => $w->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectRevenue(?Carbon $from, ?Carbon $to, int $limit, string $tz): array
    {
        $query = Order::query()->where('payment_status', 'paid');

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query->latest('id')->limit($limit)->get()->map(fn (Order $order) => [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'currency' => $order->currency,
            'subtotal' => (string) $order->subtotal,
            'grand_total' => (string) $order->grand_total,
            'payment_method' => $order->payment_method,
            'created_at' => $order->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectTraffic(?Carbon $from, ?Carbon $to, int $limit, string $tz): array
    {
        $query = DailyAggregate::query();

        if ($from) {
            $query->where('date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('date', '<=', $to->toDateString());
        }

        return $query->orderByDesc('date')->limit($limit)->get()->map(fn (DailyAggregate $d) => [
            'date' => $d->date?->toDateString(),
            'visits' => $d->visits,
            'unique_visitors' => $d->unique_visitors,
            'page_views' => $d->page_views,
            'product_views' => $d->product_views,
            'created_at' => $d->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectConversion(?Carbon $from, ?Carbon $to, int $limit, string $tz): array
    {
        $query = DailyAggregate::query();

        if ($from) {
            $query->where('date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('date', '<=', $to->toDateString());
        }

        return $query->orderByDesc('date')->limit($limit)->get()->map(fn (DailyAggregate $d) => [
            'date' => $d->date?->toDateString(),
            'visits' => $d->visits,
            'add_to_carts' => $d->add_to_carts,
            'checkouts_started' => $d->checkouts_started,
            'checkouts_completed' => $d->checkouts_completed,
            'orders_placed' => $d->orders_placed,
            'conversion_rate' => (string) $d->conversion_rate,
            'created_at' => $d->created_at?->timezone($tz)->toDateTimeString(),
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function resolveHeaders(AdminReport $report, array &$rows): array
    {
        if (empty($rows)) {
            return ! empty($report->columns) ? array_values($report->columns) : ['id'];
        }

        $allKeys = array_keys($rows[0]);

        if (! empty($report->columns)) {
            $selected = array_values(array_intersect($report->columns, $allKeys));
            if (! empty($selected)) {
                $rows = array_map(function (array $row) use ($selected): array {
                    $filtered = [];
                    foreach ($selected as $col) {
                        $filtered[$col] = $row[$col] ?? '';
                    }

                    return $filtered;
                }, $rows);

                return $selected;
            }
        }

        return $allKeys;
    }
}
