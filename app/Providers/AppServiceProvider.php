<?php

namespace App\Providers;

use App\Http\Middleware\DashboardAccess;
use App\Services\Response\ResponseSelectorInterface;
use App\Services\Response\WeightedRandomSelector;
use App\Services\Templates\FakerMethodCatalog;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResponseSelectorInterface::class, WeightedRandomSelector::class);
        $this->app->singleton(FakerMethodCatalog::class);
    }

    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            DashboardAccess::class,
        ]);

        // Fail startup if a mapped formatter disappears after a FakerPHP upgrade.
        $this->app->make(FakerMethodCatalog::class)->catalog();
    }
}
