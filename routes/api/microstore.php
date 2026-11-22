<?php

use App\Http\Controllers\Api\Microstore\MicrostoreChatController;
use App\Http\Controllers\Api\Microstore\MicrostoreDashboardController;
use App\Http\Controllers\Api\Microstore\MicrostoreOwnerController;
use App\Http\Controllers\Api\Microstore\MicrostorePublicController;
use Illuminate\Support\Facades\Route;

// ── Public Microstores ──────────────────────────────────────────────────────
Route::prefix('microstores')
    ->name('microstores.')
    ->group(function (): void {
        Route::get('/', [MicrostorePublicController::class, 'index'])->defaults('storeType', 'microstore')->name('index');
        Route::get('{slug}', [MicrostorePublicController::class, 'show'])->defaults('storeType', 'microstore')->name('show');
        Route::get('{slug}/products', [MicrostorePublicController::class, 'products'])->defaults('storeType', 'microstore')->name('products');
        Route::post('{slug}/clicks', [MicrostorePublicController::class, 'trackClick'])->defaults('storeType', 'microstore')->name('clicks');

        Route::middleware(['auth:api', 'active.session'])->group(function (): void {
            Route::get('{slug}/chat', [MicrostoreChatController::class, 'customerShow'])->defaults('storeType', 'microstore')->name('chat.show');
            Route::post('{slug}/chat/messages', [MicrostoreChatController::class, 'customerSend'])->defaults('storeType', 'microstore')->name('chat.send');
        });
    });

// ── Public Celebrity Stores ─────────────────────────────────────────────────
Route::prefix('celebrity-stores')
    ->name('celebrity-stores.')
    ->group(function (): void {
        Route::get('/', [MicrostorePublicController::class, 'index'])->defaults('storeType', 'celebrity')->name('index');
        Route::get('{slug}', [MicrostorePublicController::class, 'show'])->defaults('storeType', 'celebrity')->name('show');
        Route::get('{slug}/products', [MicrostorePublicController::class, 'products'])->defaults('storeType', 'celebrity')->name('products');
        Route::post('{slug}/clicks', [MicrostorePublicController::class, 'trackClick'])->defaults('storeType', 'celebrity')->name('clicks');

        Route::middleware(['auth:api', 'active.session'])->group(function (): void {
            Route::get('{slug}/chat', [MicrostoreChatController::class, 'customerShow'])->defaults('storeType', 'celebrity')->name('chat.show');
            Route::post('{slug}/chat/messages', [MicrostoreChatController::class, 'customerSend'])->defaults('storeType', 'celebrity')->name('chat.send');
        });
    });

// ── Creator / Owner Microstore Management ────────────────────────────────────
Route::middleware(['auth:api', 'active.session'])
    ->prefix('my-microstore')
    ->name('my-microstore.')
    ->group(function (): void {
        Route::get('/', [MicrostoreOwnerController::class, 'show'])->defaults('storeType', 'microstore')->name('show');
        Route::post('/', [MicrostoreOwnerController::class, 'store'])->defaults('storeType', 'microstore')->name('store');
        Route::patch('/', [MicrostoreOwnerController::class, 'update'])->defaults('storeType', 'microstore')->name('update');
        Route::post('products', [MicrostoreOwnerController::class, 'addProduct'])->defaults('storeType', 'microstore')->name('products.add');
        Route::patch('products/{product}', [MicrostoreOwnerController::class, 'updateProduct'])->defaults('storeType', 'microstore')->name('products.update');
        Route::delete('products/{product}', [MicrostoreOwnerController::class, 'removeProduct'])->defaults('storeType', 'microstore')->name('products.remove');
        Route::get('analytics', [MicrostoreOwnerController::class, 'analytics'])->defaults('storeType', 'microstore')->name('analytics');

        Route::get('orders', [MicrostoreDashboardController::class, 'orders'])->defaults('storeType', 'microstore')->name('orders.index');
        Route::patch('orders/bulk-status', [MicrostoreDashboardController::class, 'bulkOrderStatus'])->defaults('storeType', 'microstore')->name('orders.bulk-status');
        Route::get('inventory', [MicrostoreDashboardController::class, 'inventory'])->defaults('storeType', 'microstore')->name('inventory');
        Route::get('customers', [MicrostoreDashboardController::class, 'customers'])->defaults('storeType', 'microstore')->name('customers');
        Route::get('reviews', [MicrostoreDashboardController::class, 'reviews'])->defaults('storeType', 'microstore')->name('reviews');
        Route::get('earnings', [MicrostoreDashboardController::class, 'earnings'])->defaults('storeType', 'microstore')->name('earnings');
        Route::get('withdrawals', [MicrostoreDashboardController::class, 'withdrawals'])->defaults('storeType', 'microstore')->name('withdrawals.index');
        Route::post('withdrawals', [MicrostoreDashboardController::class, 'requestWithdrawal'])->defaults('storeType', 'microstore')->name('withdrawals.store');
        Route::get('transactions', [MicrostoreDashboardController::class, 'transactions'])->defaults('storeType', 'microstore')->name('transactions');

        Route::get('chats', [MicrostoreChatController::class, 'ownerIndex'])->defaults('storeType', 'microstore')->name('chats.index');
        Route::get('chats/{conversation}', [MicrostoreChatController::class, 'ownerShow'])->defaults('storeType', 'microstore')->name('chats.show');
        Route::post('chats/{conversation}/messages', [MicrostoreChatController::class, 'ownerReply'])->defaults('storeType', 'microstore')->name('chats.reply');
    });

// ── Celebrity Store Owner Registration & Management ──────────────────────────
Route::middleware(['auth:api', 'active.session'])
    ->prefix('my-celebrity-store')
    ->name('my-celebrity-store.')
    ->group(function (): void {
        Route::get('/', [MicrostoreOwnerController::class, 'show'])->defaults('storeType', 'celebrity')->name('show');
        Route::post('/', [MicrostoreOwnerController::class, 'store'])->defaults('storeType', 'celebrity')->name('store');
        Route::patch('/', [MicrostoreOwnerController::class, 'update'])->defaults('storeType', 'celebrity')->name('update');
        Route::post('products', [MicrostoreOwnerController::class, 'addProduct'])->defaults('storeType', 'celebrity')->name('products.add');
        Route::patch('products/{product}', [MicrostoreOwnerController::class, 'updateProduct'])->defaults('storeType', 'celebrity')->name('products.update');
        Route::delete('products/{product}', [MicrostoreOwnerController::class, 'removeProduct'])->defaults('storeType', 'celebrity')->name('products.remove');
        Route::get('analytics', [MicrostoreOwnerController::class, 'analytics'])->defaults('storeType', 'celebrity')->name('analytics');

        Route::get('orders', [MicrostoreDashboardController::class, 'orders'])->defaults('storeType', 'celebrity')->name('orders.index');
        Route::patch('orders/bulk-status', [MicrostoreDashboardController::class, 'bulkOrderStatus'])->defaults('storeType', 'celebrity')->name('orders.bulk-status');
        Route::get('inventory', [MicrostoreDashboardController::class, 'inventory'])->defaults('storeType', 'celebrity')->name('inventory');
        Route::get('customers', [MicrostoreDashboardController::class, 'customers'])->defaults('storeType', 'celebrity')->name('customers');
        Route::get('reviews', [MicrostoreDashboardController::class, 'reviews'])->defaults('storeType', 'celebrity')->name('reviews');
        Route::get('earnings', [MicrostoreDashboardController::class, 'earnings'])->defaults('storeType', 'celebrity')->name('earnings');
        Route::get('withdrawals', [MicrostoreDashboardController::class, 'withdrawals'])->defaults('storeType', 'celebrity')->name('withdrawals.index');
        Route::post('withdrawals', [MicrostoreDashboardController::class, 'requestWithdrawal'])->defaults('storeType', 'celebrity')->name('withdrawals.store');
        Route::get('transactions', [MicrostoreDashboardController::class, 'transactions'])->defaults('storeType', 'celebrity')->name('transactions');

        Route::get('chats', [MicrostoreChatController::class, 'ownerIndex'])->defaults('storeType', 'celebrity')->name('chats.index');
        Route::get('chats/{conversation}', [MicrostoreChatController::class, 'ownerShow'])->defaults('storeType', 'celebrity')->name('chats.show');
        Route::post('chats/{conversation}/messages', [MicrostoreChatController::class, 'ownerReply'])->defaults('storeType', 'celebrity')->name('chats.reply');
    });
