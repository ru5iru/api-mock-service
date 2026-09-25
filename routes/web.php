<?php

use App\Http\Controllers\Admin\ConfigTransferController;
use App\Http\Controllers\Admin\EndpointController;
use App\Http\Controllers\Admin\ResponseController;
use App\Http\Controllers\Api\ActiveEnvironmentController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\EndpointOrganizationController;
use App\Http\Controllers\Api\EnvironmentController;
use App\Http\Controllers\Api\EnvironmentVariableController;
use App\Http\Controllers\Api\FakerCatalogController;
use App\Http\Controllers\Api\ResponseTemplateController;
use App\Http\Controllers\Api\TagController;
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
        Route::view('/environments', 'admin.environments')->name('environments.index');
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

Route::prefix('api')
    ->middleware('dashboard.access')
    ->group(function (): void {
        Route::get('/faker-catalog', FakerCatalogController::class)->name('api.faker-catalog');
        Route::post('/response-templates/validate', [ResponseTemplateController::class, 'validateTemplate'])
            ->middleware('throttle:60,1')
            ->name('api.response-templates.validate');
        Route::post('/response-templates/preview', [ResponseTemplateController::class, 'preview'])
            ->middleware('throttle:60,1')
            ->name('api.response-templates.preview');
        Route::apiResource('collections', CollectionController::class);
        Route::apiResource('tags', TagController::class);
        Route::apiResource('environments', EnvironmentController::class);
        Route::post('/environments/{environment}/duplicate', [EnvironmentController::class, 'duplicate']);
        Route::get('/environments/{environment}/variables', [EnvironmentVariableController::class, 'index']);
        Route::post('/environments/{environment}/variables', [EnvironmentVariableController::class, 'store']);
        Route::get('/environments/{environment}/variables/{variable}', [EnvironmentVariableController::class, 'show']);
        Route::patch('/environments/{environment}/variables/{variable}', [EnvironmentVariableController::class, 'update']);
        Route::delete('/environments/{environment}/variables/{variable}', [EnvironmentVariableController::class, 'destroy']);
        Route::get('/active-environment', [ActiveEnvironmentController::class, 'show']);
        Route::put('/active-environment', [ActiveEnvironmentController::class, 'update']);
        Route::patch('/endpoints/{endpoint}/organization', [EndpointOrganizationController::class, 'update']);
    });
