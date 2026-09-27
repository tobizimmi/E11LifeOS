<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;

/**
 * Signed Livewire upload/preview URLs for an app served under a URL path prefix behind a
 * reverse proxy (APP_URL = https://example.com/E11LifeOS, internal requests without prefix).
 *
 * Livewire signs the route "relative", but Laravel only strips scheme and host from it, so
 * the signature covers "/E11LifeOS/livewire/upload-file" while the proxied request validates
 * "/livewire/upload-file" – every upload failed with 401 ("… could not be uploaded").
 * Here the prefix is removed before signing and added back for the browser.
 */
class PrefixAwareSignedUploadUrl extends GenerateSignedUploadUrl
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function signedRoute($name, $expiration, $parameters = [])
    {
        $prefix = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        if ($prefix === '') {
            return parent::signedRoute($name, $expiration, $parameters);
        }

        $parameters['expires'] = $expiration instanceof DateTimeInterface ? $expiration->getTimestamp() : now()->addSeconds((int) $expiration)->getTimestamp();
        ksort($parameters);

        $relative = URL::route($name, $parameters, false);

        if (str_starts_with($relative, $prefix.'/')) {
            $relative = substr($relative, strlen($prefix));
        }

        $signature = hash_hmac('sha256', $relative, (string) config('app.key'));

        return URL::to($relative.(str_contains($relative, '?') ? '&' : '?').'signature='.$signature);
    }
}
