<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\VpsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // VPS / Servers
    Route::get('/vps', [VpsController::class, 'index'])->name('vps.index');
    Route::post('/vps', [VpsController::class, 'store'])->name('vps.store');
    Route::post('/vps/check-all', [VpsController::class, 'checkAll'])->name('vps.check-all');
    Route::post('/vps/{vps}/check', [VpsController::class, 'check'])->name('vps.check');
    Route::put('/vps/{vps}', [VpsController::class, 'update'])->name('vps.update');
    Route::delete('/vps/{vps}', [VpsController::class, 'destroy'])->name('vps.destroy');
});

require __DIR__.'/auth.php';
