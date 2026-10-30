<?php

use App\Http\Controllers\Api\Admin\ActivityLogController;
use Illuminate\Support\Facades\Route;

// ── Admin audit activity logs ───────────────────────────────────────────────
Route::middleware(['auth:api', 'active.session', 'permission:audit_logs.read'])
    ->prefix('activity')
    ->name('admin.activity.')
    ->group(function (): void {
        Route::get('/', [ActivityLogController::class, 'index'])->name('index');
        Route::get('{activity}', [ActivityLogController::class, 'show'])->name('show');
    });
