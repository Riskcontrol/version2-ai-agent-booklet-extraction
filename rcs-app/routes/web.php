<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminPartnerCreditSyncController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PartnerTrackingController;
use App\Http\Controllers\UserAccountController;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['App\Http\Middleware\CheckAuth'])->group(function () {
    Route::get('/', function () {
        return view('convocation');
    })->name('dashboard');

    Route::get('/certificates', function () {
        return view('certificates');
    })->name('certificates');

    Route::get('/top-up', function () {
        return view('topup');
    })->name('topup');

    Route::get('/logs', function () {
        return view('logs');
    })->name('logs');

    Route::get('/tracking/{doc}', [PartnerTrackingController::class, 'show'])->name('tracking.show');

    Route::get('/settings', [UserAccountController::class, 'show'])->name('settings');
    Route::post('/settings/profile', [UserAccountController::class, 'updateProfile'])->name('settings.profile');
    Route::post('/settings/password', [UserAccountController::class, 'updatePassword'])->name('settings.password');

    Route::get('/admin/partner-credit-sync-events', [AdminPartnerCreditSyncController::class, 'index'])
        ->name('admin.partnerCreditSyncEvents');
    Route::get('/admin/partner-credit-sync-events/export', [AdminPartnerCreditSyncController::class, 'exportCsv'])
        ->name('admin.partnerCreditSyncEvents.export');
});
