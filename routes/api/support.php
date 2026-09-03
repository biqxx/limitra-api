<?php

use App\Http\Controllers\Api\SupportTicketAttachmentController;
use App\Http\Controllers\Api\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::post('support/tickets', [SupportTicketController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('support.tickets.store');

Route::middleware(['auth:api', 'active.session'])
    ->prefix('support/tickets')
    ->name('support.tickets.')
    ->group(function (): void {
        Route::get('/', [SupportTicketController::class, 'index'])->name('index');
        Route::get('{supportTicket}', [SupportTicketController::class, 'show'])->name('show');
        Route::post('{supportTicket}/messages', [SupportTicketController::class, 'message'])
            ->middleware('throttle:10,1')
            ->name('messages.store');
        Route::post('{supportTicket}/close', [SupportTicketController::class, 'close'])->name('close');
    });

Route::middleware(['auth:api', 'active.session'])
    ->get('support/attachments/{supportTicketAttachment}', [SupportTicketAttachmentController::class, 'show'])
    ->name('support.attachments.show');
