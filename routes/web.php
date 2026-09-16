<?php

use App\Http\Controllers\Admin\EndpointController;
use App\Http\Controllers\Admin\ResponseController;
use Illuminate\Support\Facades\Route;

Route::prefix('dashboard')
    ->name('dashboard.')
    ->middleware('dashboard.access')
    ->group(function (): void {
        Route::get('/', [EndpointController::class, 'index'])->name('endpoints.index');
        Route::get('/endpoints/create', [EndpointController::class, 'create'])->name('endpoints.create');
        Route::get('/endpoints/{endpoint}/edit', [EndpointController::class, 'edit'])->name('endpoints.edit');
        Route::delete('/endpoints/{endpoint}', [EndpointController::class, 'destroy'])->name('endpoints.destroy');
        Route::delete('/responses/{response}', [ResponseController::class, 'destroy'])->name('responses.destroy');
    });
