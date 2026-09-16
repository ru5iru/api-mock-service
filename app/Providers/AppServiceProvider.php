<?php

namespace App\Providers;

use App\Services\Response\ResponseSelectorInterface;
use App\Services\Response\WeightedRandomSelector;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResponseSelectorInterface::class, WeightedRandomSelector::class);
    }

    public function boot(): void
    {
        // Application bootstrapping is intentionally small; dashboard access is
        // isolated behind its middleware alias in bootstrap/app.php.
    }
}
