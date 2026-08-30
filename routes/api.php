<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ── Auth & Profile ────────────────────────────────────────────────────────
    require __DIR__.'/api/auth.php';

    // ── Admin controls (auth + role enforced inside the file) ─────────────────
    Route::prefix('admin')->group(function () {
        require __DIR__.'/api/admin.php';
        require __DIR__.'/api/order_admin.php';
        require __DIR__.'/api/review_admin.php';
        require __DIR__.'/api/return_admin.php';
        require __DIR__.'/api/settings.php';
        require __DIR__.'/api/queue.php';
    });

    // ── Analytics dashboard (auth + role:admin,staff enforced inside) ─────────
    require __DIR__.'/api/analytics.php';

    // ── Affiliate portal (auth + role:affiliate inside the file) ─────────────
    require __DIR__.'/api/affiliate.php';

    // ── Product catalogue (public read, auth required for writes) ─────────────
    require __DIR__.'/api/product.php';
    require __DIR__.'/api/review.php';

    // Cart browsing supports either JWT ownership or an opaque guest token.
    require __DIR__.'/api/cart.php';
    require __DIR__.'/api/commerce.php';

    // ── User-scoped resources (all require JWT) ───────────────────────────────
    Route::middleware(['auth:api', 'active.session'])->group(function () {
        require __DIR__.'/api/address.php';
        require __DIR__.'/api/order.php';
        require __DIR__.'/api/payment.php';
        require __DIR__.'/api/return.php';
    });

    // ── Meta webhook (no auth — signed by Meta app secret) ───────────────────
    require __DIR__.'/api/webhook.php';
});
