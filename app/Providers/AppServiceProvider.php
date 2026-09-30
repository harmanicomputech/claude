<?php

namespace App\Providers;

use App\Support\Deployment;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A new upload (DEPLOY_ID changed): drop compiled views and caches once.
        Deployment::refreshIfChanged();
    }
}
