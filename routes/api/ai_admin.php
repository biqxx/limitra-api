<?php

use App\Http\Controllers\Api\Admin\AIConversationAdminController;
use Illuminate\Support\Facades\Route;

// ── Admin AI conversation monitoring & oversight ────────────────────────────
Route::middleware(['auth:api', 'active.session', 'permission:ai.read'])
    ->prefix('ai/conversations')
    ->name('admin.ai.conversations.')
    ->group(function (): void {
        Route::get('/', [AIConversationAdminController::class, 'index'])->name('index');
        Route::get('{conversation}', [AIConversationAdminController::class, 'show'])->name('show');
    });

Route::middleware(['auth:api', 'active.session', 'permission:ai.manage,support.manage'])
    ->prefix('ai/conversations')
    ->name('admin.ai.conversations.')
    ->group(function (): void {
        Route::patch('{conversation}', [AIConversationAdminController::class, 'update'])->name('update');
        Route::put('{conversation}', [AIConversationAdminController::class, 'update'])->name('update.put');
    });
