<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAddonEnabled;
use App\Http\Middleware\EnsureModuleAllowed;
use App\Http\Middleware\LogSensitiveAccess;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Life\MapHttp;
use App\Support\PrefixAwareSignedUploadUrl;
use App\Support\Security\EncryptedFilesystemAdapter;
use Facades\Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Livewire;
use Psr\Http\Message\RequestInterface;

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

        // Every outgoing request uses the remembered address of its host (slow DNS on the server);
        // requests through the VPN proxy resolve their names in the private network instead.
        Http::globalMiddleware(fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
            $uri = $request->getUri();

            if (empty($options['proxy']) && $uri->getHost() !== '' && ($ip = MapHttp::address($uri->getHost()))) {
                $port = $uri->getPort() ?? ($uri->getScheme() === 'http' ? 80 : 443);
                $options['curl'][CURLOPT_RESOLVE] = [...($options['curl'][CURLOPT_RESOLVE] ?? []), "{$uri->getHost()}:{$port}:{$ip}"];
            }

            return $handler($request, $options);
        });

        // Per-route limits of the mobile API. A plain "throttle:N,M" counts every throttled route of a
        // member in one shared bucket, so busy screens used up the limit of the scan routes.
        foreach ([20, 30, 60] as $perMinute) {
            RateLimiter::for('api-'.$perMinute, fn (Request $request) => Limit::perMinute($perMinute)->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.($request->route()?->getName() ?? $request->path())));
        }

        // App sign-in: 10 tries per minute for one account (brute force) and a generous limit per address. A
        // plain per-address limit blocked every phone behind a shared address (mobile network, proxy).
        RateLimiter::for('api-login', fn (Request $request) => [
            Limit::perMinute(10)->by('login|'.mb_strtolower((string) $request->input('email', $request->input('username', '')))),
            Limit::perMinute(120)->by('login-ip|'.$request->ip()),
        ]);

        Gate::define('admin', fn (User $user) => $user->isAdmin());

        // Documents, ID scans and receipts are stored encrypted (disk "secure").
        Storage::extend('encrypted', function (Application $app, array $config) {
            $adapter = new EncryptedFilesystemAdapter(new LocalFilesystemAdapter($config['root']), $app['encrypter']);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });

        // Long passwords; in production also checked against known data leaks (haveibeenpwned, k-anonymity).
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->letters()->numbers()->uncompromised()
            : Password::min(12)->letters()->numbers());

        // Addon, module and sensitive-area checks also apply to the Livewire requests of pages
        // behind "addon:…", "module" and "sensitive.log:…" – not just their initial page load.
        Livewire::addPersistentMiddleware([EnsureAddonEnabled::class, EnsureModuleAllowed::class, LogSensitiveAccess::class]);

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
