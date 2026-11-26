<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Only MCP clients sign in with OAuth, and they use the authorization code grant.
        Passport::$deviceCodeGrantEnabled = false;
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

        Passport::authorizationView('oauth.authorize');
    }
}
