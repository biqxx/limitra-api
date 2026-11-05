<?php

use App\Http\Controllers\Api\Admin\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:support.read'])
    ->prefix('support/tickets')
    ->name('admin.support.tickets.')
    ->group(function (): void {
        Route::get('/', [SupportTicketController::class, 'index'])->name('index');
        Route::get('{supportTicket}', [SupportTicketController::class, 'show'])->name('show');
    });

Route::middleware(['auth:api', 'active.session', 'permission:support.manage'])
    ->prefix('support/tickets')
    ->name('admin.support.tickets.')
    ->group(function (): void {
        Route::patch('{supportTicket}', [SupportTicketController::class, 'update'])->name('update');
        Route::post('{supportTicket}/messages', [SupportTicketController::class, 'reply'])->name('messages.store');
    });
