<?php

namespace Tests\Feature;

use App\Models\NewsArticle;
use App\Models\Portfolio;
use App\Models\PortfolioSetting;
use App\Models\PortfolioTransaction;
use App\Models\Security;
use App\Models\User;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\NewsFeed;
use App\Support\Portfolio\Notifier;
use App\Support\Portfolio\Positions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PortfolioLegacySettingsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function investor(): User
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_custom_criteria_and_notification_options_are_saved(): void
    {
        $user = $this->investor();

        Volt::test('portfolio.settings')
            ->set('form.strategy', 'custom')
            ->set('form.min_stability_score', '55')
            ->set('form.max_pe_ratio', '30,5')
            ->set('form.max_debt_equity', '1.5')
            ->set('form.notifications_enabled', false)
            ->set('form.news_push_max_age_hours', '6')
            ->call('save')
            ->assertHasNoErrors();

        $settings = PortfolioSetting::where('user_id', $user->id)->sole();
        $this->assertSame([55.0, 30.5, 1.5, false, 6], [$settings->min_stability_score, $settings->max_pe_ratio, $settings->max_debt_equity, $settings->notifications_enabled, $settings->news_push_max_age_hours]);

        Volt::test('portfolio.settings')->set('form.max_pe_ratio', '0')->set('form.news_push_max_age_hours', '5')->call('save')
            ->assertHasErrors(['form.max_pe_ratio', 'form.news_push_max_age_hours']);
    }

    public function test_pushover_can_be_tested_and_switched_off(): void
    {
        $user = $this->investor();
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);

        Volt::test('portfolio.settings')->call('testPushover')->assertHasErrors('pushover');

        PortfolioSetting::for($user)->fill(['pushover_user_key' => 'u', 'pushover_app_token' => 't'])->save();
        Volt::test('portfolio.settings')->call('testPushover')->assertHasNoErrors();
        Http::assertSent(fn (Request $request) => $request['message'] === 'Testnachricht: Pushover funktioniert.');

        // Switched off: the notifier no longer uses Pushover.
        PortfolioSetting::for($user)->fill(['notifications_enabled' => false])->save();
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);
        app(Notifier::class)->send($user, 'Titel', 'Text');
        Http::assertNothingSent();
    }

    public function test_news_are_pushed_only_within_the_chosen_age(): void
    {
        $user = $this->investor();
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);
        $portfolio = Portfolio::factory()->for($user)->create();
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'SAP.DE']);
        Positions::recalculate($portfolio);
        PortfolioSetting::for($user)->fill(['news_push_enabled' => true, 'news_push_max_age_hours' => 3, 'pushover_user_key' => 'u', 'pushover_app_token' => 't'])->save();
        NewsArticle::create(['symbol' => 'SAP.DE', 'title' => 'Alt', 'url' => 'https://example.com/a', 'url_hash' => 'a', 'published_at' => now()->subHours(5)]);

        $this->assertSame(0, app(NewsFeed::class)->notify(app(Notifier::class)));

        NewsArticle::create(['symbol' => 'SAP.DE', 'title' => 'Neu', 'url' => 'https://example.com/b', 'url_hash' => 'b', 'published_at' => now()->subHour()]);
        $this->assertSame(1, app(NewsFeed::class)->notify(app(Notifier::class)));
    }

    public function test_tracked_securities_and_symbol_mappings_are_managed(): void
    {
        $this->investor();

        Volt::test('portfolio.settings')->set('trackedSymbol', 'nvda')->set('trackedName', 'NVIDIA')->call('addTracked')->assertSee('NVIDIA');
        $this->assertTrue(Security::firstWhere('symbol', 'NVDA')->tracked);
        Volt::test('portfolio.settings')->call('removeTracked', 'NVDA');
        $this->assertFalse(Security::firstWhere('symbol', 'NVDA')->tracked);

        Volt::test('portfolio.settings')->set('mappingKey', 'de0007164600')->set('mappingSymbol', 'sap.de')->set('mappingName', 'SAP SE')->call('addMapping')->assertHasNoErrors()->assertSee('DE0007164600');
        $this->assertSame('SAP.DE', DB::table('security_mappings')->where('input_key', 'DE0007164600')->value('symbol'));
        $this->assertSame('SAP.DE', app(MarketData::class)->resolveMany(['DE0007164600'])['DE0007164600']->symbol);

        Volt::test('portfolio.settings')->set('mappingKey', 'KEINE')->set('mappingSymbol', 'X')->call('addMapping')->assertHasErrors('mappingKey');
        Volt::test('portfolio.settings')->call('removeMapping', 'DE0007164600');
        $this->assertFalse(DB::table('security_mappings')->where('input_key', 'DE0007164600')->exists());
    }
}
