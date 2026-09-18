<?php

namespace App\Providers;

use App\Http\Middleware\DashboardAccess;
use App\Services\Response\ResponseSelectorInterface;
use App\Services\Response\WeightedRandomSelector;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResponseSelectorInterface::class, WeightedRandomSelector::class);
    }

    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            DashboardAccess::class,
        ]);
    }
}
