<?php

namespace Tests\Feature;

use App\Models\InstagramPost;
use App\Models\PortfolioSetting;
use App\Models\PriceAlert;
use App\Models\Security;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Support\ErrorReporter;
use App\Support\Portfolio\InstagramAi;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class InstagramAnalysisTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 12:00'));
        Http::preventStrayRequests();
        config(['services.instagram.apify_key' => 'apify-token', 'services.instagram.profile' => 'heikothieme.club', 'services.anthropic.key' => 'test']);
    }

    private function user(): User
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        return $user;
    }

    public function test_posts_are_fetched_analysed_and_new_ideas_pushed(): void
    {
        Http::fake([
            'api.apify.com/*' => Http::response([
                ['id' => '111', 'shortCode' => 'ABC', 'caption' => 'Infineon: Idee des Tages bei 30 Euro, Stop 27', 'timestamp' => '2026-09-24T18:00:00Z', 'displayUrl' => 'https://cdn.example/abc.jpg', 'videoDuration' => 95],
                ['id' => '222', 'shortCode' => 'DEF', 'caption' => 'Marktausblick', 'timestamp' => '2026-09-23T18:00:00Z'],
            ]),
            'api.pushover.net/*' => Http::response(['status' => 1]),
        ]);

        $this->mock(InstagramAi::class, fn (MockInterface $mock) => $mock->shouldReceive('analyze')->twice()->andReturnUsing(fn (InstagramPost $post) => $post->shortcode === 'ABC'
            ? ['summary' => 'Kaufidee Infineon.', 'tickers' => ['IFX.DE'], 'stock_name' => 'Infineon', 'sentiment' => 'bullish', 'key_points' => ['Chips'], 'entry_price' => 30.0, 'entry_type' => 'Idee des Tages', 'stop_loss' => 27.0, 'currency' => 'EUR', 'tranches' => [30.0, 27.5], 'analyzed_at' => now()]
            : ['summary' => 'Ausblick.', 'tickers' => [], 'sentiment' => 'neutral', 'key_points' => [], 'tranches' => [], 'analyzed_at' => now()]));

        $user = $this->user();
        PortfolioSetting::for($user)->fill(['instagram_push' => true, 'pushover_user_key' => 'u', 'pushover_app_token' => 't'])->save();

        $this->artisan('portfolio:instagram')->expectsOutputToContain('New posts: 2, analysed: 2, notifications: 1')->assertSuccessful();

        $post = InstagramPost::where('shortcode', 'ABC')->sole();
        $this->assertEquals(['IFX.DE', 30.0, [30, 27.5], 95], [$post->mainTicker(), $post->entry_price, $post->tranches, $post->duration_sec]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'apify') && $request['directUrls'] === ['https://www.instagram.com/heikothieme.club/']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pushover') && str_contains($request['title'], 'Infineon'));

        // Already known posts are neither analysed nor announced again.
        $this->artisan('portfolio:instagram')->expectsOutputToContain('New posts: 0, analysed: 0, notifications: 0');
    }

    public function test_a_refused_apify_key_alerts_the_admins_once_instead_of_an_error(): void
    {
        Http::fake(['api.apify.com/*' => Http::response(['error' => ['type' => 'insufficient-permissions']], 403)]);
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->once()->andReturn(1));
        [$admin] = $this->memberWithHousehold();
        $admin->forceFill(['is_admin' => true])->save();

        $this->artisan('portfolio:instagram')->expectsOutputToContain('APIFY_API_KEY')->assertSuccessful();
        $this->artisan('portfolio:instagram')->assertSuccessful();

        $this->assertSame([], Cache::get(ErrorReporter::CACHE_KEY, []));
    }

    public function test_the_page_compares_with_the_current_price_and_creates_alerts(): void
    {
        $user = $this->user();
        Security::factory()->create(['symbol' => 'IFX.DE', 'name' => 'Infineon', 'last_price' => 29.1, 'currency' => 'EUR', 'price_updated_at' => now()]);
        $post = InstagramPost::create(['profile' => 'heikothieme.club', 'instagram_id' => '1', 'shortcode' => 'ABC', 'post_url' => 'https://www.instagram.com/p/ABC/', 'published_at' => now(), 'summary' => 'Kaufidee Infineon.', 'tickers' => ['IFX.DE'], 'stock_name' => 'Infineon', 'sentiment' => 'bullish', 'entry_price' => 30, 'stop_loss' => 27, 'tranches' => [30, 27.5], 'analyzed_at' => now()]);
        InstagramPost::create(['profile' => 'heikothieme.club', 'instagram_id' => '2', 'shortcode' => 'DEF', 'published_at' => now()->subDay(), 'summary' => 'Ausblick auf den Markt.', 'sentiment' => 'neutral', 'analyzed_at' => now()]);

        Volt::test('portfolio.instagram')
            ->assertSee('Kaufidee Infineon.')->assertSee('im Kaufbereich')->assertSee('Ausblick auf den Markt.')
            ->set('ideasOnly', true)->assertDontSee('Ausblick auf den Markt.')
            ->call('addToWatchlist', $post->id)
            ->call('alerts', $post->id)
            ->call('togglePush');

        $this->assertSame('IFX.DE', WatchlistItem::where('user_id', $user->id)->sole()->symbol);
        $this->assertEqualsCanonicalizing([30.0, 27.5], PriceAlert::where('user_id', $user->id)->pluck('target_price')->all());
        $this->assertTrue(PortfolioSetting::where('user_id', $user->id)->sole()->instagram_push);
    }

    public function test_long_signed_media_links_are_stored(): void
    {
        $link = 'https://instagram.fbcn12-1.fna.fbcdn.net/o1/v/t16/f2/m86/video.mp4?'.str_repeat('efg=abcdefghij&', 120);

        $post = InstagramPost::create(['profile' => 'example', 'instagram_id' => '42', 'shortcode' => 'abc', 'thumbnail_url' => $link, 'video_url' => $link]);

        $this->assertSame($link, $post->fresh()->video_url);
    }

    public function test_videos_can_be_pushed_with_the_push_key(): void
    {
        $payload = ['videos' => [['instagram_id' => '999', 'shortcode' => 'PI1', 'caption' => 'Video', 'transcript' => 'Heute schauen wir auf SAP …', 'published_at' => '2026-09-25 08:00:00', 'ai_summary' => '', 'ai_tickers' => '[]']]];

        $this->postJson(route('instagram.push'), $payload)->assertStatus(503);

        config(['services.instagram.push_key' => 'geheim']);
        $this->postJson(route('instagram.push'), $payload, ['X-Push-Key' => 'falsch'])->assertUnauthorized();
        $this->postJson(route('instagram.push'), $payload, ['X-Push-Key' => 'geheim'])->assertOk()->assertJson(['success' => true, 'saved' => 1]);

        $post = InstagramPost::sole();
        // Transcript without summary: the AI reads it with the next run.
        $this->assertSame(['PI1', 'Heute schauen wir auf SAP …', null], [$post->shortcode, $post->transcript, $post->analyzed_at]);
    }

    public function test_instagram_videos_of_the_old_app_are_imported(): void
    {
        [$user] = $this->memberWithHousehold();
        config(['database.connections.portfolio_legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $legacy = Schema::connection('portfolio_legacy');
        $legacy->create('users', fn ($table) => [$table->id(), $table->string('email')]);
        $legacy->create('portfolios', fn ($table) => [$table->id(), $table->integer('user_id'), $table->string('name')]);
        $legacy->create('instagram_videos', fn ($table) => [$table->id(), $table->string('instagram_id'), $table->string('shortcode'), $table->text('caption')->nullable(), $table->string('thumbnail_url')->nullable(), $table->string('video_url')->nullable(), $table->string('post_url')->nullable(), $table->dateTime('published_at'), $table->integer('duration_sec')->nullable(), $table->integer('view_count')->nullable(), $table->text('transcript')->nullable(), $table->text('ai_summary')->nullable(), $table->text('ai_tickers')->nullable(), $table->string('ai_sentiment')->nullable(), $table->text('ai_key_points')->nullable(), $table->text('ai_tranches')->nullable(), $table->string('ai_stock_name')->nullable(), $table->decimal('ai_entry_price')->nullable(), $table->string('ai_entry_type')->nullable(), $table->decimal('ai_stop_loss')->nullable(), $table->string('ai_currency')->nullable(), $table->dateTime('analyzed_at')->nullable()]);
        DB::connection('portfolio_legacy')->table('users')->insert(['id' => 1, 'email' => $user->email]);
        DB::connection('portfolio_legacy')->table('instagram_videos')->insert(['instagram_id' => '5', 'shortcode' => 'OLD', 'published_at' => '2026-01-10 10:00:00', 'ai_summary' => 'Alt', 'ai_tickers' => '["SAP.DE"]', 'ai_sentiment' => 'bullish', 'ai_tranches' => '[120, 110]', 'ai_entry_price' => 120, 'analyzed_at' => '2026-01-10 11:00:00']);

        $this->artisan('portfolio:import-legacy', ['email' => $user->email, '--without-prices' => true])->assertSuccessful();

        $post = InstagramPost::sole();
        $this->assertSame(['SAP.DE', [120, 110], 'bullish'], [$post->mainTicker(), $post->tranches, $post->sentiment]);
        $this->assertNotNull($post->notified_at);
    }
}
