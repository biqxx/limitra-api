<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
use App\Models\Payment\WalletDeposit;
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

        $items = collect();

        if ($type === 'all' || $type === 'deposit') {
            $deposits = WalletDeposit::query()
                ->with('user:id,name,email')
                ->when($status, fn ($query, $s) => $query->where('status', $s))
                ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q) {
                    $sub->where('reference', 'ilike', "%{$q}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%"));
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
                    'user' => $d->user ? ['id' => $d->user->id, 'name' => $d->user->name, 'email' => $d->user->email] : null,
                    'created_at' => $d->created_at?->toIso8601String(),
                ]);
            $items = $items->concat($deposits);
        }

        if ($type === 'all' || $type === 'payment') {
            $payments = Payment::query()
                ->with(['user:id,name,email', 'order:id,order_number'])
                ->when($status, fn ($query, $s) => $query->where('status', $s))
                ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q) {
                    $sub->where('reference', 'ilike', "%{$q}%")
                        ->orWhere('customer_email', 'ilike', "%{$q}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%"));
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
                    'order_number' => $p->order?->order_number,
                    'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'email' => $p->user->email] : null,
                    'created_at' => $p->created_at?->toIso8601String(),
                ]);
            $items = $items->concat($payments);
        }

        if ($type === 'all' || $type === 'order') {
            $orders = Order::query()
                ->with('user:id,name,email')
                ->when($status, fn ($query, $s) => $query->where('payment_status', $s)->orWhere('status', $s))
                ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q) {
                    $sub->where('order_number', 'ilike', "%{$q}%")
                        ->orWhere('contact_email', 'ilike', "%{$q}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%"));
                }))
                ->latest('id')
                ->limit($fetchLimit)
                ->get()
                ->map(fn (Order $o) => [
                    'id' => 'ord-' . $o->id,
                    'record_id' => $o->id,
                    'type' => 'order',
                    'reference' => $o->order_number ?: ('ORD-' . $o->id),
                    'amount' => number_format((float) $o->total_amount + (float) ($o->credit_total ?? 0), 2, '.', ''),
                    'wallet_credit' => number_format((float) ($o->credit_total ?? 0), 2, '.', ''),
                    'currency' => $o->currency ?? 'NGN',
                    'status' => $o->payment_status ?: $o->status,
                    'method' => $o->payment_method,
                    'provider' => (float) ($o->credit_total ?? 0) > 0 && (float) $o->total_amount === 0.0 ? 'wallet' : 'paystack',
                    'order_id' => $o->id,
                    'order_number' => $o->order_number,
                    'user' => $o->user ? ['id' => $o->user->id, 'name' => $o->user->name, 'email' => $o->user->email] : null,
                    'created_at' => $o->created_at?->toIso8601String(),
                ]);
            $items = $items->concat($orders);
        }

        if ($type === 'all' || $type === 'refund') {
            $refunds = Refund::query()
                ->with(['user:id,name,email', 'order:id,order_number'])
                ->when($status, fn ($query, $s) => $query->where('status', $s))
                ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q) {
                    $sub->where('reference', 'ilike', "%{$q}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%"));
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
                    'order_number' => $r->order?->order_number,
                    'reason' => $r->reason,
                    'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name, 'email' => $r->user->email] : null,
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
}
