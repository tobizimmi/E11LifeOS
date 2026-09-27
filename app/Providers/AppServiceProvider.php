<?php

namespace App\Providers;

use App\Models\User;
use App\Support\AppSettings;
use App\Support\PrefixAwareSignedUploadUrl;
use Facades\Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Illuminate\Support\Facades\Gate;
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
        // Keys and mail settings entered on the admin page override .env.
        AppSettings::apply();

        Gate::define('admin', fn (User $user) => $user->isAdmin());

        // In production this app is reached through a reverse proxy that maps a
        // URL path (e.g. https://zimmimail.de/E11LifeOS) onto an unprefixed
        // internal PHP process. Laravel otherwise builds route()/url()/asset()
        // links from the incoming (unprefixed) request, which would silently
        // drop the path prefix. Forcing the root URL from APP_URL fixes that.
        if ($this->app->environment(['production', 'staging'])) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');

            // Livewire's own <script> tag for livewire.min.js is NOT built via the
            // url()/route() helpers above — it hardcodes a root-relative path
            // ("/livewire/livewire.min.js"), which 404s once the app lives under a
            // URL path prefix. livewire.asset_url expects the FULL file path (not
            // just a base to prefix), see FrontendAssets::js().
            $filename = config('app.debug') ? '/livewire/livewire.js' : '/livewire/livewire.min.js';
            config(['livewire.asset_url' => rtrim(config('app.url'), '/').$filename]);

            // File uploads: sign the upload URL without the path prefix (see the class).
            GenerateSignedUploadUrl::swap(new PrefixAwareSignedUploadUrl);
        }
    }
}
