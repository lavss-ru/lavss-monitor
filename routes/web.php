<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\VpsController;
use App\Http\Controllers\WebsiteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/local-infrastructure', [\App\Http\Controllers\LocationController::class, 'index'])->name('local-infrastructure.index');
    Route::resource('locations', \App\Http\Controllers\LocationController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/local-devices/check-all', [\App\Http\Controllers\LocalDeviceController::class, 'checkAll'])->name('local-devices.check-all');
    Route::post('/local-devices/{localDevice}/check', [\App\Http\Controllers\LocalDeviceController::class, 'check'])->name('local-devices.check');
    Route::resource('local-devices', \App\Http\Controllers\LocalDeviceController::class)
        ->only(['store', 'update', 'destroy'])->parameters(['local-devices' => 'localDevice']);

    // VPS / Servers
    Route::get('/vps', [VpsController::class, 'index'])->name('vps.index');
    Route::post('/vps', [VpsController::class, 'store'])->name('vps.store');
    Route::post('/vps/check-all', [VpsController::class, 'checkAll'])->name('vps.check-all');
    Route::post('/vps/{vps}/check', [VpsController::class, 'check'])->name('vps.check');
    Route::put('/vps/{vps}', [VpsController::class, 'update'])->name('vps.update');
    Route::delete('/vps/{vps}', [VpsController::class, 'destroy'])->name('vps.destroy');

    // Websites
    Route::get('/websites', [WebsiteController::class, 'index'])->name('websites.index');
    Route::post('/websites', [WebsiteController::class, 'store'])->name('websites.store');
    Route::post('/websites/check-all', [WebsiteController::class, 'checkAll'])->name('websites.check-all');
    Route::post('/websites/{website}/check', [WebsiteController::class, 'check'])->name('websites.check');
    Route::put('/websites/{website}', [WebsiteController::class, 'update'])->name('websites.update');
    Route::delete('/websites/{website}', [WebsiteController::class, 'destroy'])->name('websites.destroy');
});

require __DIR__.'/auth.php';
