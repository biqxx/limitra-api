<?php

use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\RoleMemberController;
use App\Http\Controllers\Api\Admin\UserAccessController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:roles.read'])->group(function () {
    Route::get('roles', [RoleController::class, 'index'])->name('admin.roles.index');
    Route::get('roles/{role}', [RoleController::class, 'show'])->name('admin.roles.show');
    Route::get('permissions', [PermissionController::class, 'index'])->name('admin.permissions.index');
    Route::get('roles/{role}/members', [RoleMemberController::class, 'index'])->name('admin.roles.members.index');
});

Route::middleware(['auth:api', 'active.session', 'permission:roles.manage'])->group(function () {
    Route::post('roles', [RoleController::class, 'store'])->name('admin.roles.store');
    Route::patch('roles/{role}', [RoleController::class, 'update'])->name('admin.roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('admin.roles.destroy');
    Route::post('roles/{role}/members', [RoleMemberController::class, 'store'])->name('admin.roles.members.store');
    Route::delete('roles/{role}/members/{user}', [RoleMemberController::class, 'destroy'])->name('admin.roles.members.destroy');
    Route::patch('staff/{user}/role', [RoleMemberController::class, 'replace'])->name('admin.staff.role.update');

    Route::patch('users/{user}/role', [UserAccessController::class, 'updateRole'])->name('admin.users.update-role');
    Route::delete('users/{user}', [UserAccessController::class, 'destroy'])->name('admin.users.destroy');
});
