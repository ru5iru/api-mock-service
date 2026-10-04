<?php

use App\Http\Controllers\Api\VerificationController;
use App\Http\Middleware\VerifyApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/verify')->middleware(VerifyApiToken::class)->name('verification.')->group(function (): void {
    // UUIDs are stable across portable configuration and independent of local IDs.
    Route::get('/endpoints/{endpoint:uuid}/calls', [VerificationController::class, 'calls'])->name('calls');
    Route::post('/endpoints/{endpoint:uuid}/assert', [VerificationController::class, 'assertCalls'])->name('assert');
    Route::post('/endpoints/{endpoint:uuid}/reset', [VerificationController::class, 'reset'])->name('reset');
    Route::post('/reset-all', [VerificationController::class, 'resetAll'])->name('reset-all');
    Route::any('/{invalid?}', fn () => response()->json(['message' => 'Unknown verification API route.'], 404))->where('invalid', '.*');
});
