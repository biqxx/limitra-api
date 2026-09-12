<?php

use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\RoleMemberController;
use App\Http\Controllers\Api\Admin\StaffInvitationController;
use App\Http\Controllers\Api\Admin\UserAccessController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:roles.read'])->group(function () {
    Route::get('roles', [RoleController::class, 'index'])->name('admin.roles.index');
    Route::get('roles/{role}', [RoleController::class, 'show'])->name('admin.roles.show');
    Route::get('permissions', [PermissionController::class, 'index'])->name('admin.permissions.index');
    Route::get('roles/{role}/members', [RoleMemberController::class, 'index'])->name('admin.roles.members.index');
    Route::get('staff/invitations', [StaffInvitationController::class, 'index'])->name('admin.staff-invitations.index');
});

Route::middleware(['auth:api', 'active.session', 'permission:roles.manage'])->group(function () {
    Route::post('roles', [RoleController::class, 'store'])->name('admin.roles.store');
    Route::patch('roles/{role}', [RoleController::class, 'update'])->name('admin.roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');
    Route::post('roles/{role}/members', [RoleMemberController::class, 'store'])->name('admin.roles.members.store');
    Route::delete('roles/{role}/members/{user}', [RoleMemberController::class, 'destroy'])->name('admin.roles.members.destroy');
    Route::patch('staff/{user}/role', [RoleMemberController::class, 'replace'])->name('admin.staff.role.update');
    Route::post('staff/invitations', [StaffInvitationController::class, 'store'])
        ->middleware('throttle:10,1')->name('admin.staff-invitations.store');
    Route::post('staff/invitations/{staffInvitation}/resend', [StaffInvitationController::class, 'resend'])
        ->middleware('throttle:5,15')->name('admin.staff-invitations.resend');
    Route::delete('staff/invitations/{staffInvitation}', [StaffInvitationController::class, 'destroy'])
        ->name('admin.staff-invitations.destroy');

    Route::patch('users/{user}/role', [UserAccessController::class, 'updateRole'])->name('admin.users.update-role');
    Route::delete('users/{user}', [UserAccessController::class, 'destroy'])->name('admin.users.destroy');
});

Route::middleware(['auth:api', 'active.session', 'permission:customers.update'])->group(function () {
    Route::patch('users/{user}/status', [UserAccessController::class, 'updateStatus'])->name('admin.users.status.update');
    Route::post('users/{user}/suspend', [UserAccessController::class, 'suspend'])->name('admin.users.suspend');
    Route::post('users/{user}/restore', [UserAccessController::class, 'restore'])->name('admin.users.restore');
    Route::post('users/{user}/password-reset', [UserAccessController::class, 'passwordReset'])
        ->middleware('throttle:5,15')->name('admin.users.password-reset');
});
