<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
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
        // The site is served separately and Filament ships its own assets, so `composer run dev` only
        // needs the queue worker (searches run there), the scheduler (weekly scans) and the log tail.
        DevCommands::except('server', 'vite');
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}
