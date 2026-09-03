<?php

use App\Http\Controllers\Api\Admin\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'role:admin,staff'])
    ->prefix('support/tickets')
    ->name('admin.support.tickets.')
    ->group(function (): void {
        Route::get('/', [SupportTicketController::class, 'index'])->name('index');
        Route::patch('{supportTicket}', [SupportTicketController::class, 'update'])->name('update');
    });
