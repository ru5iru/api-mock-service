<?php

use App\Http\Middleware\DashboardAccess;
use App\Http\Middleware\VerifyApiToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/verification.php'));
            Route::middleware('api')
                ->group(base_path('routes/mock.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Authenticate before UUID binding so nonexistent IDs do not bypass the API gate.
        $middleware->prependToPriorityList(SubstituteBindings::class, VerifyApiToken::class);
        $middleware->alias([
            'dashboard.access' => DashboardAccess::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Prune the most recent 50 revisions per entity; run nightly at 02:00.
        $schedule->command('mockdeck:prune-revisions', ['--keep=50'])->dailyAt('02:00');
        // Keep 90 days of callback-attempt and MCP-invocation history.
        $schedule->command('mockdeck:prune-callback-attempts', ['--days=90'])->dailyAt('02:15');
        $schedule->command('mockdeck:prune-mcp-invocations', ['--days=90'])->dailyAt('02:30');
        // Short-lived MCP idempotency keys and unmatched evidence (existing command).
        $schedule->command('mockdeck:mcp-prune')->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/v1/verify*') || (! $request->is('dashboard*') && $request->expectsJson()),
        );
    })->create();
