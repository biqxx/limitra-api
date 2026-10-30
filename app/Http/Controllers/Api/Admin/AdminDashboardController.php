<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Affiliate\AffiliateApplication;
use App\Models\Affiliate\AffiliateWithdrawal;
use App\Models\Order\Order;
use App\Models\Order\ReturnRequest;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends BaseController
{
    public function dashboard(Request $request): JsonResponse
    {
        $ordersCount = Order::query()->count();
        $totalRevenue = Order::query()->where('payment_status', 'paid')->sum('total_amount');
        $customersCount = User::query()->where('role', 'user')->count();
        $productsCount = Product::query()->where('status', 'active')->count();
        $lowStockCount = Product::query()->where('status', 'active')->where('stock', '<=', 5)->count();

        $pendingApplications = AffiliateApplication::query()->where('status', 'pending')->count();
        $pendingWithdrawals = AffiliateWithdrawal::query()->where('status', 'pending')->count();
        $pendingReturns = ReturnRequest::query()->where('status', 'pending')->count();

        return $this->success([
            'metrics' => [
                'total_orders' => $ordersCount,
                'total_revenue' => number_format((float) $totalRevenue, 2, '.', ''),
                'total_customers' => $customersCount,
                'active_products' => $productsCount,
                'low_stock_products' => $lowStockCount,
            ],
            'pending_actions' => [
                'affiliate_applications' => $pendingApplications,
                'affiliate_withdrawals' => $pendingWithdrawals,
                'return_requests' => $pendingReturns,
            ],
        ], 'Admin dashboard metrics retrieved.');
    }

    public function health(Request $request): JsonResponse
    {
        $dbStatus = 'healthy';
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbStatus = 'unhealthy';
        }

        $cacheStatus = 'healthy';
        try {
            Cache::put('health_check', true, 10);
            $cacheStatus = Cache::get('health_check') ? 'healthy' : 'degraded';
        } catch (\Throwable) {
            $cacheStatus = 'unhealthy';
        }

        $overall = ($dbStatus === 'healthy' && $cacheStatus === 'healthy') ? 'healthy' : 'degraded';

        return $this->success([
            'status' => $overall,
            'timestamp' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'checks' => [
                'database' => $dbStatus,
                'cache' => $cacheStatus,
            ],
        ], 'System health status.');
    }
}
