<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Force Firestore to use REST transport to avoid gRPC infinite recursion in WSL2
        $this->app->singleton(Factory::class, fn () => (new Factory())->withFirestoreClientConfig([
            'transport' => 'rest',
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
