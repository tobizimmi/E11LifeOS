<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplyUrlPrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class UrlPrefixTest extends TestCase
{
    use RefreshDatabase;

    private function behindTheProxy(): void
    {
        $this->app['env'] = 'staging';
        config(['app.url' => 'https://zimmimail.de/E11LifeOS-staging']);
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme('https');
    }

    public function test_urls_seen_by_php_are_rewritten_onto_the_public_address(): void
    {
        $this->behindTheProxy();

        $this->assertSame('https://zimmimail.de/E11LifeOS-staging/finance?tab=forecast', ApplyUrlPrefix::publicUrl('http://127.0.0.1:3020/finance?tab=forecast'));
        $this->assertSame('https://zimmimail.de/E11LifeOS-staging/portfolio/banking', ApplyUrlPrefix::publicUrl('https://127.0.0.1:3020/E11LifeOS-staging/portfolio/banking'));
        $this->assertSame('https://zimmimail.de/E11LifeOS-staging/dashboard', ApplyUrlPrefix::publicUrl('https://zimmimail.de/E11LifeOS-staging/dashboard'));
    }

    public function test_the_page_to_return_to_after_signing_in_keeps_the_public_address(): void
    {
        $this->behindTheProxy();

        // Apache forwards to the internal PHP server without the public host and path prefix.
        $this->get('http://127.0.0.1:3020/finance?tab=forecast')->assertRedirect();
        $this->get('http://127.0.0.1:3020/login')->assertOk();

        $this->assertSame('https://zimmimail.de/E11LifeOS-staging/finance?tab=forecast', session('url.intended'));
    }

    public function test_back_after_a_form_error_keeps_the_public_address(): void
    {
        $this->behindTheProxy();
        Route::post('/_test/back', fn () => back())->middleware('web');

        $this->get('http://127.0.0.1:3020/login')->assertOk();

        $this->post('http://127.0.0.1:3020/_test/back', ['_token' => session()->token()])->assertRedirect('https://zimmimail.de/E11LifeOS-staging/login');
    }
}
