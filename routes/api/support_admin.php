<?php

use App\Http\Controllers\Api\Admin\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:support.read'])
    ->prefix('support/tickets')
    ->name('admin.support.tickets.')
    ->group(function (): void {
        Route::get('/', [SupportTicketController::class, 'index'])->name('index');
    });

Route::middleware(['auth:api', 'active.session', 'permission:support.manage'])
    ->patch('support/tickets/{supportTicket}', [SupportTicketController::class, 'update'])
    ->name('admin.support.tickets.update');
