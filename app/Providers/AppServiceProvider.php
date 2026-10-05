<?php

namespace App\Providers;

use App\Contracts\AvailabilityChecker;
use App\Contracts\BrowserLauncher;
use App\Contracts\ProcessExecutor;
use App\Services\HttpAvailabilityChecker;
use App\Services\SymfonyProcessExecutor;
use App\Services\SystemBrowserLauncher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProcessExecutor::class, SymfonyProcessExecutor::class);
        $this->app->singleton(AvailabilityChecker::class, HttpAvailabilityChecker::class);
        $this->app->singleton(BrowserLauncher::class, SystemBrowserLauncher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('openai', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
