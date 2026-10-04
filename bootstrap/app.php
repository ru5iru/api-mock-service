<?php

use App\Http\Middleware\DashboardAccess;
use App\Http\Middleware\VerifyApiToken;
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
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/v1/verify*') || (! $request->is('dashboard*') && $request->expectsJson()),
        );
    })->create();
