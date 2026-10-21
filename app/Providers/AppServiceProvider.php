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
        // Override the package transport so GraphQL reads/mutations share the REST budget.
        $this->app->singleton(\Softonic\GraphQL\Client::class, fn () => \App\Support\ArborHttp::graphQlClient());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
