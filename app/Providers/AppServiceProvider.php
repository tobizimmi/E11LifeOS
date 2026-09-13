<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        // In production this app is reached through a reverse proxy that maps a
        // URL path (e.g. https://zimmimail.de/E11LifeOS) onto an unprefixed
        // internal PHP process. Laravel otherwise builds route()/url()/asset()
        // links from the incoming (unprefixed) request, which would silently
        // drop the path prefix. Forcing the root URL from APP_URL fixes that.
        if ($this->app->environment(['production', 'staging'])) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }
    }
}
