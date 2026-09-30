<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_the_page_to_return_to_keeps_the_path_prefix_of_the_proxy(): void
    {
        $this->behindTheProxy();

        $this->get('http://zimmimail.de/finance?tab=forecast')->assertRedirect();

        $this->assertSame('https://zimmimail.de/E11LifeOS-staging/finance?tab=forecast', session('url.intended'));
    }

    public function test_a_prefix_sent_by_a_client_is_ignored(): void
    {
        $this->withHeader('X-Forwarded-Prefix', '/evil')->get('/finance')->assertRedirect();

        $this->assertStringNotContainsString('/evil', (string) session('url.intended'));
    }
}
