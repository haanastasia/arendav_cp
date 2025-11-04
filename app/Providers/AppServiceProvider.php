<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Trip;  
use App\Observers\TripObserver;  

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
        Trip::observe(TripObserver::class);
    }
}
