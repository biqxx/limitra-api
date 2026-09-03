<?php

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
    });
