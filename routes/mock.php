<?php

use App\Http\Controllers\MockInvocationController;
use App\Http\Middleware\ResolveTestRun;
use Illuminate\Support\Facades\Route;

Route::any('/mcp/{any?}', fn () => response()->json(['error' => 'MCP transport is not enabled'], 404))->where('any', '.*');
Route::any('/_mockdeck/run/{publicId}/{any?}', MockInvocationController::class)
    ->where('any', '.*')->middleware(ResolveTestRun::class)->name('mock.invoke.run');
Route::any('/_mockdeck/{any?}', fn () => response()->json(['error' => 'Reserved MockDeck routing path'], 404))->where('any', '.*');

Route::any('/', MockInvocationController::class)->middleware(ResolveTestRun::class)->name('mock.invoke.root');

Route::any('/{any}', MockInvocationController::class)
    ->where('any', '(?!dashboard(?:/|$)|livewire(?:-|/|$)|up$|css(?:/|$)).*')
    ->middleware(ResolveTestRun::class)->name('mock.invoke');
