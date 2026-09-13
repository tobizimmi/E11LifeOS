<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class SubpathUrlGenerationTest extends TestCase
{
    public function test_generated_urls_are_forced_to_app_url_in_production(): void
    {
        config(['app.url' => 'https://zimmimail.de/E11LifeOS']);
        $this->app['env'] = 'production';

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('https://zimmimail.de/E11LifeOS/dashboard', route('dashboard'));
        $this->assertSame('https://zimmimail.de/E11LifeOS/manifest.json', asset('manifest.json'));
    }

    public function test_urls_are_not_forced_outside_production_and_staging(): void
    {
        config(['app.url' => 'https://zimmimail.de/E11LifeOS']);
        $this->app['env'] = 'local';

        (new AppServiceProvider($this->app))->boot();

        $this->assertNotSame('https://zimmimail.de/E11LifeOS/dashboard', route('dashboard'));
    }
}
