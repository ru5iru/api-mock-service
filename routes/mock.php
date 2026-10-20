<?php

use App\Http\Controllers\MockInvocationController;
use App\Http\Middleware\AuditRejectedMcpCalls;
use App\Http\Middleware\AuthenticateMcpCredential;
use App\Http\Middleware\McpBoundary;
use App\Http\Middleware\ResolveTestRun;
use App\Mcp\MockDeckServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware([McpBoundary::class, 'throttle:mcp-auth', AuthenticateMcpCredential::class, 'throttle:mcp', AuditRejectedMcpCalls::class])->group(function (): void {
    Mcp::web('/mcp', MockDeckServer::class);
});

Route::any('/mcp/{any?}', fn () => response()->json(['error' => 'Reserved MCP path; connect to /mcp'], 404))->where('any', '.*');
Route::any('/_mockdeck/run/{publicId}/{any?}', MockInvocationController::class)
    ->where('any', '.*')->middleware(ResolveTestRun::class)->name('mock.invoke.run');
Route::any('/_mockdeck/{any?}', fn () => response()->json(['error' => 'Reserved MockDeck routing path'], 404))->where('any', '.*');

Route::any('/', MockInvocationController::class)->middleware(ResolveTestRun::class)->name('mock.invoke.root');

Route::any('/{any}', MockInvocationController::class)
    ->where('any', '(?!dashboard(?:/|$)|livewire(?:-|/|$)|up$|css(?:/|$)).*')
    ->middleware(ResolveTestRun::class)->name('mock.invoke');
