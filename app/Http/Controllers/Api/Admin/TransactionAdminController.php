<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
use App\Models\Payment\WalletDeposit;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionAdminController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $type = strtolower((string) $request->query('type', 'all'));
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));
        $perPage = min(max($request->integer('per_page', 50), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $fetchLimit = $page * $perPage;
        $userSearch = $this->userSearchFilter($q);

        $items = collect();

        if ($type === 'all' || $type === 'deposit') {
            $deposits = WalletDeposit::query()
                ->with('user:id,username,email')
                ->when($status, fn ($query, $s) => $query->where('status', $s))
                ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q, $userSearch) {
                    $sub->where('reference', 'ilike', "%{$q}%")
                        ->orWhereHas('user', $userSearch);
                }))
                ->latest('id')
                ->limit($fetchLimit)
                ->get()
                ->map(fn (WalletDeposit $d) => [
                    'id' => 'dep-' . $d->id,
                    'record_id' => $d->id,
                    'type' => 'deposit',
                    'reference' => $d->reference,
                    'amount' => number_format($d->amount_minor / 100, 2, '.', ''),
                    'wallet_credit' => '0.00',
                    'currency' => $d->currency ?? 'NGN',
                    'status' => $d->status,
                    'method' => $d->method ?? 'card',
                    'provider' => 'paystack',
                    'order_id' => null,
                    'order_number' => null,
                    'user' => $this->formatUser($d->user),
                    'created_at' => $d->created_at?->toIso8601String(),
                ]);
            $items = $items->concat($deposits);
        }

        if ($type === 'all' || $type === 'payment') {
            $payments = Payment::query()
                ->with(['user:id,username,email', 'order:id,number'])
                ->when($status, fn ($query, $s) => $query->where('status', $s))
                ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q, $userSearch) {
                    $sub->where('reference', 'ilike', "%{$q}%")
                        ->orWhere('customer_email', 'ilike', "%{$q}%")
                        ->orWhereHas('user', $userSearch);
                }))
                ->latest('id')
                ->limit($fetchLimit)
                ->get()
                ->map(fn (Payment $p) => [
                    'id' => 'pay-' . $p->id,
                    'record_id' => $p->id,
                    'type' => 'payment',
                    'reference' => $p->reference,
                    'amount' => number_format((float) $p->amount, 2, '.', ''),
                    'wallet_credit' => '0.00',
                    'currency' => $p->currency ?? 'NGN',
                    'status' => $p->status,
                    'method' => $p->method,
                    'provider' => $p->provider,
                    'order_id' => $p->order_id,
                    'order_number' => $p->order?->number,
                    'user' => $this->formatUser($p->user),
                    'created_at' => $p->created_at?->toIso8601String(),
                ]);
            $items = $items->concat($payments);
        }

        if ($type === 'all' || $type === 'order' || $type === 'promo') {
            $orders = Order::query()
                ->with('user:id,username,email')
                ->when($type === 'promo', fn ($query) => $query->where('discount_total', '>', 0))
                ->when($status, fn ($query, $s) => $query->where(function ($sub) use ($s) {
                    $sub->where('payment_status', $s)->orWhere('status', $s);
                }))
                ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q, $userSearch) {
                    $sub->where('number', 'ilike', "%{$q}%")
                        ->orWhere('contact_email', 'ilike', "%{$q}%")
                        ->orWhereHas('user', $userSearch);
                }))
                ->latest('id')
                ->limit($fetchLimit)
                ->get();

            if ($type === 'all' || $type === 'order') {
                $items = $items->concat($orders->map(function (Order $o) {
                    $originalAmount = (float) $o->total_amount + (float) ($o->credit_total ?? 0) + (float) ($o->discount_total ?? 0);

                    return [
                        'id' => 'ord-' . $o->id,
                        'record_id' => $o->id,
                        'type' => 'order',
                        'reference' => $o->number ?: ('ORD-' . $o->id),
                        'amount' => number_format($originalAmount, 2, '.', ''),
                        'wallet_credit' => number_format((float) ($o->credit_total ?? 0), 2, '.', ''),
                        'discount_total' => number_format((float) ($o->discount_total ?? 0), 2, '.', ''),
                        'currency' => $o->currency ?? 'NGN',
                        'status' => $o->payment_status ?: $o->status,
                        'method' => $o->payment_method,
                        'provider' => (float) ($o->credit_total ?? 0) > 0 && (float) $o->total_amount === 0.0 ? 'wallet' : 'paystack',
                        'order_id' => $o->id,
                        'order_number' => $o->number,
                        'user' => $this->formatUser($o->user),
                        'created_at' => $o->created_at?->toIso8601String(),
                    ];
                }));
            }

            if ($type === 'all' || $type === 'promo') {
                $promoItems = $orders
                    ->filter(fn (Order $o) => (float) ($o->discount_total ?? 0) > 0)
                    ->map(fn (Order $o) => [
                        'id' => 'prm-' . $o->id,
                        'record_id' => $o->id,
                        'type' => 'promo',
                        'reference' => 'PROMO · ' . ($o->number ?: ('ORD-' . $o->id)),
                        'amount' => number_format(-1 * (float) $o->discount_total, 2, '.', ''),
                        'wallet_credit' => '0.00',
                        'currency' => $o->currency ?? 'NGN',
                        'status' => $o->payment_status ?: $o->status,
                        'method' => 'coupon / promo',
                        'provider' => 'promotion',
                        'order_id' => $o->id,
                        'order_number' => $o->number,
                        'user' => $this->formatUser($o->user),
                        'created_at' => $o->created_at?->toIso8601String(),
                    ]);
                $items = $items->concat($promoItems);
            }
        }

        if ($type === 'all' || $type === 'refund') {
            $refunds = Refund::query()
                ->with(['user:id,username,email', 'order:id,number'])
                ->when($status, fn ($query, $s) => $query->where('status', $s))
                ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q, $userSearch) {
                    $sub->where('reference', 'ilike', "%{$q}%")
                        ->orWhereHas('user', $userSearch);
                }))
                ->latest('id')
                ->limit($fetchLimit)
                ->get()
                ->map(fn (Refund $r) => [
                    'id' => 'ref-' . $r->id,
                    'record_id' => $r->id,
                    'type' => 'refund',
                    'reference' => $r->reference,
                    'amount' => number_format((float) $r->amount, 2, '.', ''),
                    'wallet_credit' => '0.00',
                    'currency' => $r->currency ?? 'NGN',
                    'status' => $r->status,
                    'method' => $r->method,
                    'provider' => $r->provider,
                    'order_id' => $r->order_id,
                    'order_number' => $r->order?->number,
                    'reason' => $r->reason,
                    'user' => $this->formatUser($r->user),
                    'created_at' => $r->created_at?->toIso8601String(),
                ]);
            $items = $items->concat($refunds);
        }

        $sorted = $items->sortByDesc('created_at')->values();
        $total = $sorted->count();
        $paged = $sorted->forPage($page, $perPage)->values();

        return $this->success([
            'items' => $paged,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ], 'Admin transactions retrieved.');
    }

    private function formatUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->username ?: $user->email,
            'email' => $user->email,
        ];
    }

    private function userSearchFilter(string $q): Closure
    {
        return fn ($u) => $u->where('username', 'ilike', "%{$q}%")
            ->orWhere('email', 'ilike', "%{$q}%");
    }
}
