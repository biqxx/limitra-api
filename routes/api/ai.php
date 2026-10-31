<?php

use App\Http\Controllers\Api\AIConversationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session'])
    ->prefix('ai/conversations')
    ->name('ai.conversations.')
    ->group(function (): void {
        Route::post('/', [AIConversationController::class, 'store'])
            ->middleware('throttle:10,1')->name('store');
        Route::get('{conversation}/messages', [AIConversationController::class, 'messages'])->name('messages.index');
        Route::post('{conversation}/messages', [AIConversationController::class, 'message'])
            ->middleware('throttle:20,1')->name('messages.store');
        Route::post('{conversation}/handoff', [AIConversationController::class, 'handoff'])
            ->name('handoff');
        Route::get('{conversation}/attachments/{attachment}', [AIConversationController::class, 'attachment'])
            ->name('attachments.download');
        Route::delete('{conversation}', [AIConversationController::class, 'destroy'])->name('destroy');
    });
