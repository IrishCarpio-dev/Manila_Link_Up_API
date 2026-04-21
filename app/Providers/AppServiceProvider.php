<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Force gRPC to use poll() instead of epoll1 — WSL2's epoll is unstable under gRPC
        // and causes intermittent infinite recursion in CredentialsWrapper.
        // Must be set before the first gRPC channel is created.
        putenv('GRPC_POLL_STRATEGY=poll');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
