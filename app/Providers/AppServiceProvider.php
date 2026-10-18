<?php

namespace App\Providers;

use App\Http\Middleware\DashboardAccess;
use App\Services\Environments\EnvironmentContext;
use App\Services\Response\ResponseSelectorInterface;
use App\Services\Response\WeightedRandomSelector;
use App\Services\Templates\FakerMethodCatalog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResponseSelectorInterface::class, WeightedRandomSelector::class);
        $this->app->singleton(FakerMethodCatalog::class);
        $this->app->singleton(EnvironmentContext::class);
    }

    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            DashboardAccess::class,
        ]);

        // Future route order: throttle:mcp-auth, AuthenticateMcpCredential, throttle:mcp.
        RateLimiter::for('mcp-auth', fn (Request $request) => Limit::perMinute(config('mcp.rate_limits.auth_per_minute', 30))->by('mcp-auth:'.$request->ip()));
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(config('mcp.rate_limits.tools_per_minute', 60))->by('mcp:'.($request->attributes->get('mcp_credential')?->id ?? $request->ip())));

        // Fail startup if a mapped formatter disappears after a FakerPHP upgrade.
        $this->app->make(FakerMethodCatalog::class)->catalog();
    }
}
