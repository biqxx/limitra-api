<?php

use App\Http\Controllers\Api\Admin\AIConversationAdminController;
use App\Http\Controllers\Api\Admin\AIKnowledgeDocumentController;
use Illuminate\Support\Facades\Route;

// ── Admin AI conversation monitoring & oversight ────────────────────────────
Route::middleware(['auth:api', 'active.session', 'permission:ai.read'])
    ->prefix('ai')
    ->name('admin.ai.')
    ->group(function (): void {
        Route::get('metrics', [AIConversationAdminController::class, 'metrics'])->name('metrics');
        Route::get('settings', [AIConversationAdminController::class, 'settings'])->name('settings');

        Route::prefix('conversations')->name('conversations.')->group(function (): void {
            Route::get('/', [AIConversationAdminController::class, 'index'])->name('index');
            Route::get('{conversation}', [AIConversationAdminController::class, 'show'])->name('show');
        });
    });

Route::middleware(['auth:api', 'active.session', 'permission:ai.settings.manage'])
    ->prefix('ai')
    ->name('admin.ai.')
    ->group(function (): void {
        Route::patch('settings', [AIConversationAdminController::class, 'updateSettings'])->name('settings.update');
    });

Route::middleware(['auth:api', 'active.session', 'permission:ai.manage,support.manage'])
    ->prefix('ai/conversations')
    ->name('admin.ai.conversations.')
    ->group(function (): void {
        Route::patch('{conversation}', [AIConversationAdminController::class, 'update'])->name('update');
        Route::put('{conversation}', [AIConversationAdminController::class, 'update'])->name('update.put');
    });

// ── Admin AI Knowledge Base Management ─────────────────────────────────────
Route::middleware(['auth:api', 'active.session', 'permission:ai.knowledge.manage'])
    ->prefix('ai/knowledge')
    ->name('admin.ai.knowledge.')
    ->group(function (): void {
        Route::get('/', [AIKnowledgeDocumentController::class, 'index'])->name('index');
        Route::post('/', [AIKnowledgeDocumentController::class, 'store'])->name('store');
        Route::get('{document}', [AIKnowledgeDocumentController::class, 'show'])->name('show');
        Route::match(['put', 'patch'], '{document}', [AIKnowledgeDocumentController::class, 'update'])->name('update');
        Route::post('{document}/index', [AIKnowledgeDocumentController::class, 'indexDocument'])->name('index-doc');
        Route::delete('{document}', [AIKnowledgeDocumentController::class, 'destroy'])->name('destroy');
    });
