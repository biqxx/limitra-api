<?php

use App\Http\Controllers\Api\AccountComplianceController;
use App\Http\Controllers\Api\AgeVerificationController;
use App\Http\Controllers\Api\PrivacyConsentController;
use Illuminate\Support\Facades\Route;

// ── Public / Semi-public privacy & age-gate routes ───────────────────────────
Route::get('privacy/consents', [PrivacyConsentController::class, 'show'])->name('privacy.consents.show');
Route::put('privacy/consents', [PrivacyConsentController::class, 'update'])->name('privacy.consents.update');
Route::post('age-verification', [AgeVerificationController::class, 'verify'])->name('age-verification.verify');

// ── Authenticated GDPR compliance & data rights ──────────────────────────────
Route::middleware(['auth:api', 'active.session'])->group(function (): void {
    Route::post('account/data-export', [AccountComplianceController::class, 'exportData'])->name('account.data-export.store');
    Route::get('account/data-export/{export}', [AccountComplianceController::class, 'getExport'])->name('account.data-export.show');
    Route::get('account/data-export/{export}/download', [AccountComplianceController::class, 'downloadExport'])->name('account.data-export.download');
    Route::post('account/deletion-request', [AccountComplianceController::class, 'requestDeletion'])->name('account.deletion-request.store');
    Route::delete('account/deletion-request', [AccountComplianceController::class, 'cancelDeletion'])->name('account.deletion-request.destroy');
});
