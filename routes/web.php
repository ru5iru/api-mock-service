<?php

use App\Http\Controllers\Admin\ConfigTransferController;
use App\Http\Controllers\Admin\EndpointController;
use App\Http\Controllers\Admin\ResponseController;
use App\Http\Controllers\Auth\DashboardSessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('dashboard')->name('dashboard.')->group(function (): void {
    Route::get('/login', [DashboardSessionController::class, 'create'])->name('login');
    Route::post('/login', [DashboardSessionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

Route::prefix('dashboard')
    ->name('dashboard.')
    ->middleware('dashboard.access')
    ->group(function (): void {
        Route::post('/logout', [DashboardSessionController::class, 'destroy'])->name('logout');
        Route::get('/', [EndpointController::class, 'index'])->name('endpoints.index');
        Route::view('/requests', 'admin.requests')->name('requests.index');
        Route::view('/docs', 'admin.docs')->name('docs');
        Route::view('/config', 'admin.config')->name('config.index');
        Route::post('/config/exports', [ConfigTransferController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('config.exports.store');
        Route::post('/config/imports/preview', [ConfigTransferController::class, 'preview'])
            ->middleware('throttle:20,1')
            ->name('config.imports.preview');
        Route::post('/config/imports/apply', [ConfigTransferController::class, 'apply'])
            ->middleware('throttle:20,1')
            ->name('config.imports.apply');
        Route::get('/endpoints/create', [EndpointController::class, 'create'])->name('endpoints.create');
        Route::get('/endpoints/{endpoint}/edit', [EndpointController::class, 'edit'])->name('endpoints.edit');
        Route::delete('/endpoints/{endpoint}', [EndpointController::class, 'destroy'])->name('endpoints.destroy');
        Route::delete('/responses/{response}', [ResponseController::class, 'destroy'])->name('responses.destroy');
    });
