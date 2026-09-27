<?php

namespace Tests\Feature;

use App\Support\PrefixAwareSignedUploadUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FileUploadBehindProxyTest extends TestCase
{
    public function test_upload_urls_are_signed_without_the_proxy_path_prefix(): void
    {
        config(['app.url' => 'https://zimmimail.de/E11LifeOS']);
        URL::forceRootUrl('https://zimmimail.de/E11LifeOS');
        URL::forceScheme('https');

        $url = (new PrefixAwareSignedUploadUrl)->signedRoute('livewire.upload-file', now()->addMinutes(5));

        // The browser posts to the public URL with the prefix …
        $this->assertStringStartsWith('https://zimmimail.de/E11LifeOS/livewire/upload-file?expires=', $url);

        // … and the proxy hands the app the path without it: the signature must still match.
        $query = parse_url($url, PHP_URL_QUERY);
        $internal = Request::create('http://127.0.0.1:8000/livewire/upload-file?'.$query, 'POST');
        $this->assertTrue($internal->hasValidRelativeSignature());

        $tampered = Request::create('http://127.0.0.1:8000/livewire/upload-file?'.str_replace('expires=', 'expires=1', $query), 'POST');
        $this->assertFalse($tampered->hasValidRelativeSignature());

        // Without a prefix Livewire's own signing is used.
        config(['app.url' => 'https://example.com']);
        URL::forceRootUrl('https://example.com');
        $plain = (new PrefixAwareSignedUploadUrl)->signedRoute('livewire.upload-file', now()->addMinutes(5));
        $this->assertTrue(Request::create('http://example.com/livewire/upload-file?'.parse_url($plain, PHP_URL_QUERY), 'POST')->hasValidRelativeSignature());
    }
}
