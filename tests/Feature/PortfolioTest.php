<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSetting;
use App\Models\PortfolioTransaction;
use App\Models\PriceAlert;
use App\Models\Security;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Support\Portfolio\Positions;
use App\Support\Portfolio\TransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /** @var array<string, array{0: float, 1: float, 2: string}> symbol => [price, previous close, currency] */
    private array $prices = [
        'SAP.DE' => [120.0, 118.0, 'EUR'],
        'AAPL' => [200.0, 190.0, 'USD'],
        'USDEUR=X' => [0.9, 0.9, 'EUR'],
        'IFX.DE' => [30.0, 31.0, 'EUR'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 12:00'));
        Http::preventStrayRequests();
        Http::fake([
            'query1.finance.yahoo.com/v8/finance/chart/*' => function ($request) {
                $symbol = urldecode(explode('?', basename($request->url()))[0]);

                return isset($this->prices[$symbol]) ? Http::response($this->chart($symbol, ...$this->prices[$symbol])) : Http::response([], 404);
            },
            'query1.finance.yahoo.com/v1/finance/search*' => Http::response(['quotes' => [
                ['symbol' => 'SAP.DE', 'longname' => 'SAP SE', 'exchDisp' => 'XETRA', 'quoteType' => 'EQUITY'],
            ]]),
            'api.openfigi.com/*' => Http::response([
                ['data' => [['ticker' => 'IFX', 'exchCode' => 'GY', 'name' => 'INFINEON TECHNOLOGIES AG', 'figi' => 'BBG000C8K2P9']]],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chart(string $symbol, float $price, float $previous, string $currency): array
    {
        return ['chart' => ['result' => [[
            'meta' => ['symbol' => $symbol, 'currency' => $currency, 'regularMarketPrice' => $price, 'longName' => $symbol.' Name', 'exchangeName' => 'GER', 'instrumentType' => 'EQUITY', 'fiftyTwoWeekHigh' => $price * 1.2, 'fiftyTwoWeekLow' => $price * 0.7],
            'timestamp' => [now()->subDays(2)->timestamp, now()->subDay()->timestamp, now()->timestamp],
            'indicators' => ['quote' => [['close' => [$previous - 1, $previous, $price], 'open' => [null, null, null], 'high' => [null, null, null], 'low' => [null, null, null], 'volume' => [1, 2, 3]]]],
        ]]]];
    }

    /**
     * @return array{0: User, 1: Household, 2: Portfolio}
     */
    private function investor(): array
    {
        [$user, $household] = $this->memberWithHousehold();
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Trade Republic']);
        $this->actingAs($user);

        return [$user, $household, $portfolio];
    }

    public function test_positions_follow_the_transactions_with_the_average_cost_method(): void
    {
        [, , $portfolio] = $this->investor();
        $add = fn (array $attributes) => PortfolioTransaction::factory()->for($portfolio)->create($attributes);

        $add(['type' => 'buy', 'quantity' => 10, 'price' => 100, 'fees' => 10, 'executed_on' => '2026-01-10']);
        $add(['type' => 'buy', 'quantity' => 10, 'price' => 120, 'fees' => 0, 'executed_on' => '2026-02-10']);
        $add(['type' => 'sell', 'quantity' => 5, 'price' => 150, 'fees' => 1, 'executed_on' => '2026-03-10']);
        $add(['type' => 'split', 'quantity' => 0, 'price' => 2, 'fees' => 0, 'executed_on' => '2026-04-10']);
        $add(['symbol' => 'AAPL', 'name' => 'Apple', 'isin' => null, 'type' => 'buy', 'quantity' => 2, 'price' => 110, 'fees' => 0, 'currency' => 'USD', 'exchange_rate' => 1.1, 'executed_on' => '2026-01-05']);
        $add(['symbol' => 'OLD.DE', 'type' => 'buy', 'quantity' => 1, 'price' => 10, 'executed_on' => '2026-01-01']);
        $add(['symbol' => 'OLD.DE', 'type' => 'sell', 'quantity' => 1, 'price' => 12, 'executed_on' => '2026-02-01']);

        $manual = $portfolio->positions()->create(['symbol' => 'GOLD', 'name' => 'Gold', 'quantity' => 1, 'avg_buy_price' => 1800, 'manual' => true]);
        $stale = $portfolio->positions()->create(['symbol' => 'GONE', 'name' => 'Gone', 'quantity' => 1, 'avg_buy_price' => 1]);

        Positions::recalculate($portfolio);
        $portfolio->positions()->where('symbol', 'SAP.DE')->update(['labels' => json_encode(['Core'])]);
        Positions::recalculate($portfolio);

        $sap = $portfolio->positions()->where('symbol', 'SAP.DE')->sole();
        // 2210 € cost for 20 shares → 110.50; 5 sold → 15 shares at 1657.50; split 2:1 → 30 shares at 55.25.
        $this->assertSame([30.0, 55.25, ['Core']], [$sap->quantity, $sap->avg_buy_price, $sap->labels]);

        $apple = $portfolio->positions()->where('symbol', 'AAPL')->sole();
        $this->assertSame([2.0, 100.0, 'EUR'], [$apple->quantity, $apple->avg_buy_price, $apple->currency]);

        $this->assertNull($portfolio->positions()->where('symbol', 'OLD.DE')->first());
        $this->assertModelExists($manual);
        $this->assertModelMissing($stale);
    }

    public function test_the_overview_values_positions_in_euro_with_current_prices(): void
    {
        [$user, , $portfolio] = $this->investor();
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'SAP.DE', 'quantity' => 10, 'price' => 100, 'fees' => 0]);
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'AAPL', 'name' => 'Apple', 'isin' => null, 'quantity' => 1, 'price' => 150, 'fees' => 0]);
        Positions::recalculate($portfolio);

        $component = Volt::test('portfolio.index')->assertSee('Trade Republic')->assertSee('SAP SE');
        $summary = $component->viewData('summary');

        // SAP 10 × 120 € + Apple 200 $ × 0.9 = 1380 €; cost 1150 €; today 10 × 2 € + 10 $ × 0.9.
        $this->assertSame([1380.0, 1150.0, 230.0, 29.0], [$summary['value'], $summary['cost'], $summary['gain'], $summary['day_change']]);
        $this->assertSame(120.0, Security::firstWhere('symbol', 'SAP.DE')->last_price);

        // Snapshots feed the value chart.
        $component->call('refreshPrices');
        $this->assertSame(1, $portfolio->snapshots()->count());
        $this->travel(1)->days();
        $this->artisan('portfolio:update-prices')->assertSuccessful();
        $this->assertSame(2, $portfolio->snapshots()->count());
        $this->assertCount(2, Volt::test('portfolio.index')->viewData('chart'));
    }

    public function test_portfolios_and_manual_positions_are_managed(): void
    {
        [$user, , $portfolio] = $this->investor();

        Volt::test('portfolio.index')->call('newPortfolio')->set('portfolioForm.name', 'ETF-Depot')->call('savePortfolio')->assertHasNoErrors();
        $etf = Portfolio::where('user_id', $user->id)->where('name', 'ETF-Depot')->sole();

        Volt::test('portfolio.index')
            ->call('newPosition')
            ->set('positionForm.portfolio_id', (string) $etf->id)
            ->set('positionForm.symbol', 'sap.de')
            ->set('positionForm.quantity', '3')
            ->set('positionForm.avg_buy_price', '99,50')
            ->set('positionForm.labels', 'Core, Dividende')
            ->call('savePosition')
            ->assertHasNoErrors();

        $position = $etf->positions()->sole();
        $this->assertSame(['SAP.DE', 3.0, 99.5, ['Core', 'Dividende'], true], [$position->symbol, $position->quantity, $position->avg_buy_price, $position->labels, $position->manual]);

        Volt::test('portfolio.index')->call('deletePosition', $position->id);
        $this->assertModelMissing($position);

        Volt::test('portfolio.index')->call('deletePortfolio', $etf->id);
        $this->assertModelMissing($etf);
    }

    public function test_transactions_are_added_edited_and_deleted(): void
    {
        [, , $portfolio] = $this->investor();

        $this->get(route('portfolio.transactions', ['new' => 1, 'symbol' => 'SAP.DE']))->assertOk();

        Volt::test('portfolio.transactions')
            ->call('newTransaction', 'SAP.DE')
            ->set('form.quantity', '10')
            ->set('form.price', '100,00')
            ->set('form.fees', '1')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $this->assertSame([10.0, 100.1], [$portfolio->positions()->sole()->quantity, $portfolio->positions()->sole()->avg_buy_price]);

        Volt::test('portfolio.transactions')
            ->call('newTransaction', 'SAP.DE')
            ->set('form.type', 'sell')
            ->set('form.quantity', '11')
            ->set('form.price', '120')
            ->call('saveTransaction')
            ->assertHasErrors('form.quantity')
            ->set('form.quantity', '10')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $this->assertSame(0, $portfolio->positions()->count());

        $sale = $portfolio->transactions()->where('type', 'sell')->sole();
        $this->assertSame(1200.0, $sale->cashFlow());
        Volt::test('portfolio.transactions')->call('deleteTransaction', $sale->id);
        $this->assertSame(10.0, $portfolio->positions()->sole()->quantity);
    }

    public function test_broker_csv_exports_are_imported_once_and_isins_resolved(): void
    {
        [, , $portfolio] = $this->investor();
        $csv = "Datum;Typ;Symbol;ISIN;Name;Menge;Kurs;Betrag;Gebühren;Währung\n"
            ."15.03.2026;Kauf;;DE0006231004;Infineon;20;28,50;-570,00;1,00;EUR\n"
            ."20.04.2026;Dividende;;DE0006231004;Infineon;20;0,35;7,00;0;EUR\n"
            ."01.05.2026;Unbekannt;;DE0006231004;Infineon;1;1;1;0;EUR\n";

        $component = Volt::test('portfolio.import')
            ->set('file', UploadedFile::fake()->createWithContent('export.csv', $csv))
            ->assertHasNoErrors()
            ->assertSet('detectedBroker', 'trade_republic')
            ->assertCount('rows', 2)
            ->call('import');

        $this->assertSame(['imported' => 2, 'skipped' => 0], $component->get('result'));
        $position = $portfolio->positions()->sole();
        $this->assertSame(['IFX.DE', 'DE0006231004', 20.0], [$position->symbol, $position->isin, $position->quantity]);
        $this->assertSame('DE0006231004', Security::firstWhere('symbol', 'IFX.DE')->isin);

        // Importing the same export again adds nothing.
        $again = Volt::test('portfolio.import')->set('file', UploadedFile::fake()->createWithContent('export.csv', $csv))->call('import');
        $this->assertSame(['imported' => 0, 'skipped' => 2], $again->get('result'));
        $this->assertSame(2, $portfolio->transactions()->count());

        Volt::test('portfolio.import')->set('file', UploadedFile::fake()->createWithContent('empty.csv', "foo;bar\n1;2\n"))->assertHasErrors('file');
    }

    public function test_the_watchlist_and_security_page_show_prices_and_history(): void
    {
        [$user] = $this->investor();

        Volt::test('portfolio.watchlist')->set('name', 'SAP SE')->set('symbol', 'SAP.DE')->assertSee('SAP SE')->assertSee('120,00 €');
        $item = WatchlistItem::where('user_id', $user->id)->sole();

        $this->get(route('portfolio.security', ['symbol' => 'SAP.DE']))->assertOk()->assertSee('SAP.DE');
        $page = Volt::test('portfolio.security', ['symbol' => 'SAP.DE']);
        $this->assertNotEmpty($page->viewData('chart'));
        $page->call('toggleWatchlist');
        $this->assertModelMissing($item);

        $this->assertSame(['SAP.DE'], array_column(Volt::test('portfolio.watchlist')->call('searchSecurities', 'SAP')->effects['returns'][0], 'symbol'));
        $this->get(route('portfolio.security', ['symbol' => 'NOPE']))->assertNotFound();
    }

    public function test_portfolios_are_private_and_the_addon_can_be_switched_off(): void
    {
        [$user, $household, $portfolio] = $this->investor();
        $partner = $this->addMemberTo($household);
        $foreign = Portfolio::factory()->for($partner)->create(['name' => 'Geheimdepot']);
        $foreignTransaction = PortfolioTransaction::factory()->for($foreign)->create();

        Volt::test('portfolio.index')->assertDontSee('Geheimdepot')->call('editPortfolio', $foreign->id)->assertNotFound();
        Volt::test('portfolio.transactions')->call('editTransaction', $foreignTransaction->id)->assertNotFound();
        Volt::test('portfolio.import')->set('portfolioId', (string) $foreign->id)->set('rows', [app(TransactionImporter::class)->normalize(['symbol' => 'SAP.DE', 'transaction_date' => '2026-01-01', 'type' => 'buy', 'quantity' => 1])])->call('import')->assertNotFound();

        $household->setAddon('portfolio', false);
        $this->get(route('portfolio.index'))->assertNotFound();
    }

    public function test_settings_are_saved_and_the_pushover_token_stays_secret(): void
    {
        [$user, , $portfolio] = $this->investor();

        Volt::test('portfolio.settings')
            ->set('form.default_portfolio_id', (string) $portfolio->id)
            ->set('form.tax_allowance', '2000')
            ->set('form.pushover_user_key', 'user-key')
            ->set('form.pushover_app_token', 'secret-token')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('form.pushover_app_token', '')
            ->assertDontSee('secret-token');

        $settings = PortfolioSetting::where('user_id', $user->id)->sole();
        $this->assertSame([$portfolio->id, 2000.0, 'secret-token'], [$settings->default_portfolio_id, $settings->tax_allowance, $settings->pushover_app_token]);
        $this->assertNotSame('secret-token', DB::table('portfolio_settings')->value('pushover_app_token'));

        // Saving again without a token keeps it.
        Volt::test('portfolio.settings')->call('save');
        $this->assertSame('secret-token', $settings->fresh()->pushover_app_token);
    }

    public function test_the_old_portfolio_manager_database_is_imported(): void
    {
        [$user] = $this->memberWithHousehold();
        config(['database.connections.portfolio_legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $legacy = Schema::connection('portfolio_legacy');
        $legacy->create('users', fn ($table) => [$table->id(), $table->string('email')]);
        $legacy->create('portfolios', fn ($table) => [$table->id(), $table->integer('user_id'), $table->string('name'), $table->string('broker')->nullable(), $table->string('description')->nullable(), $table->string('color')->nullable(), $table->timestamp('created_at')->nullable()]);
        $legacy->create('positions', fn ($table) => [$table->id(), $table->integer('portfolio_id'), $table->string('symbol'), $table->string('name'), $table->string('isin')->nullable(), $table->decimal('quantity'), $table->decimal('avg_buy_price'), $table->string('currency'), $table->string('asset_type'), $table->text('notes')->nullable(), $table->string('labels')->nullable(), $table->decimal('stop_loss_price')->nullable(), $table->decimal('take_profit_price')->nullable()]);
        $legacy->create('transactions', fn ($table) => [$table->id(), $table->integer('portfolio_id'), $table->string('symbol'), $table->string('name'), $table->string('isin')->nullable(), $table->string('wkn')->nullable(), $table->string('type'), $table->decimal('quantity'), $table->decimal('price'), $table->decimal('fees'), $table->decimal('taxes'), $table->string('currency'), $table->decimal('exchange_rate'), $table->date('transaction_date'), $table->string('broker')->nullable(), $table->text('notes')->nullable(), $table->timestamp('created_at')->nullable()]);
        $legacy->create('watchlist', fn ($table) => [$table->id(), $table->integer('user_id'), $table->string('symbol'), $table->string('name'), $table->text('notes')->nullable()]);
        $legacy->create('user_settings', fn ($table) => [$table->id(), $table->integer('user_id'), $table->string('strategy'), $table->decimal('freistellungsauftrag'), $table->string('benchmark_symbol'), $table->integer('default_portfolio_id')->nullable(), $table->string('pushover_user_key')->nullable()]);
        $legacy->create('price_alerts', fn ($table) => [$table->id(), $table->integer('user_id'), $table->string('symbol'), $table->string('name')->nullable(), $table->string('condition'), $table->decimal('target_price'), $table->string('currency'), $table->boolean('triggered'), $table->dateTime('triggered_at')->nullable(), $table->boolean('active')]);
        $legacy->create('price_history', fn ($table) => [$table->id(), $table->string('symbol'), $table->date('date'), $table->decimal('close'), $table->decimal('open')->nullable(), $table->decimal('high')->nullable(), $table->decimal('low')->nullable(), $table->bigInteger('volume')->nullable()]);

        $db = DB::connection('portfolio_legacy');
        $db->table('users')->insert([['id' => 7, 'email' => $user->email], ['id' => 8, 'email' => 'someone@example.com']]);
        $db->table('portfolios')->insert([['id' => 3, 'user_id' => 7, 'name' => 'Comdirect', 'color' => '#ff0000'], ['id' => 4, 'user_id' => 8, 'name' => 'Fremd', 'color' => '#000000']]);
        $db->table('transactions')->insert([
            ['portfolio_id' => 3, 'symbol' => 'SAP.DE', 'name' => 'SAP SE', 'isin' => 'DE0007164600', 'type' => 'buy', 'quantity' => 4, 'price' => 100, 'fees' => 4, 'taxes' => 0, 'currency' => 'EUR', 'exchange_rate' => 1, 'transaction_date' => '2025-06-01'],
            ['portfolio_id' => 4, 'symbol' => 'AAPL', 'name' => 'Apple', 'isin' => null, 'type' => 'buy', 'quantity' => 1, 'price' => 100, 'fees' => 0, 'taxes' => 0, 'currency' => 'EUR', 'exchange_rate' => 1, 'transaction_date' => '2025-06-01'],
        ]);
        $db->table('positions')->insert([
            ['portfolio_id' => 3, 'symbol' => 'SAP.DE', 'name' => 'SAP SE', 'quantity' => 4, 'avg_buy_price' => 101, 'currency' => 'EUR', 'asset_type' => 'stock', 'labels' => 'Core,Tech', 'stop_loss_price' => 80],
            ['portfolio_id' => 3, 'symbol' => 'BTC-EUR', 'name' => 'Bitcoin', 'quantity' => 0.5, 'avg_buy_price' => 30000, 'currency' => 'EUR', 'asset_type' => 'crypto', 'labels' => null, 'stop_loss_price' => null],
        ]);
        $db->table('watchlist')->insert(['user_id' => 7, 'symbol' => 'NVDA', 'name' => 'NVIDIA', 'notes' => 'Rücksetzer abwarten']);
        $db->table('user_settings')->insert(['user_id' => 7, 'strategy' => 'thieme', 'freistellungsauftrag' => 801, 'benchmark_symbol' => '^GSPC', 'default_portfolio_id' => 3, 'pushover_user_key' => 'abc']);
        $db->table('price_alerts')->insert(['user_id' => 7, 'symbol' => 'SAP.DE', 'condition' => 'below', 'target_price' => 90, 'currency' => 'EUR', 'triggered' => false, 'active' => true]);
        $db->table('price_history')->insert([['symbol' => 'SAP.DE', 'date' => '2026-09-23', 'close' => 117], ['symbol' => 'SAP.DE', 'date' => '2026-09-24', 'close' => 118]]);

        $this->artisan('portfolio:import-legacy', ['email' => $user->email])->assertSuccessful();
        $this->artisan('portfolio:import-legacy', ['email' => $user->email])->assertSuccessful();

        $portfolio = Portfolio::where('user_id', $user->id)->sole();
        $this->assertSame(['Comdirect', '#ff0000', 3, 1], [$portfolio->name, $portfolio->color, $portfolio->legacy_id, $portfolio->transactions()->count()]);
        $sap = $portfolio->positions()->where('symbol', 'SAP.DE')->sole();
        $this->assertSame([4.0, 101.0, ['Core', 'Tech'], 80.0, false], [$sap->quantity, $sap->avg_buy_price, $sap->labels, $sap->stop_loss_price, $sap->manual]);
        $this->assertTrue($portfolio->positions()->where('symbol', 'BTC-EUR')->sole()->manual);
        $this->assertSame('Rücksetzer abwarten', WatchlistItem::where('user_id', $user->id)->sole()->notes);
        $settings = PortfolioSetting::where('user_id', $user->id)->sole();
        $this->assertSame(['thieme', 801.0, '^GSPC', $portfolio->id, 'abc'], [$settings->strategy, $settings->tax_allowance, $settings->benchmark_symbol, $settings->default_portfolio_id, $settings->pushover_user_key]);
        $this->assertSame(1, PriceAlert::where('user_id', $user->id)->count());
        $this->assertSame(2, DB::table('security_prices')->where('symbol', 'SAP.DE')->count());
        $this->assertSame(0, PortfolioPosition::where('symbol', 'AAPL')->count());

        $this->artisan('portfolio:import-legacy', ['email' => 'nobody@example.com'])->assertFailed();

        // --all takes the data of every old account.
        $this->artisan('portfolio:import-legacy', ['email' => $user->email, '--all' => true])->assertSuccessful();
        $this->assertSame(['Comdirect', 'Fremd'], Portfolio::where('user_id', $user->id)->orderBy('legacy_id')->pluck('name')->all());
        $this->assertSame(1, PortfolioPosition::where('symbol', 'AAPL')->count());
        $this->assertSame(1, PriceAlert::where('user_id', $user->id)->count());
    }
}
