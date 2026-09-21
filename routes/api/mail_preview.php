<?php

use App\Http\Controllers\Api\Admin\MailPreviewController;
use Illuminate\Support\Facades\Route;

Route::get('mail-preview', MailPreviewController::class)
    ->middleware(['auth:api', 'active.session', 'permission:mail_logs.read', 'throttle:10,1'])
    ->name('admin.mail-preview.index');
