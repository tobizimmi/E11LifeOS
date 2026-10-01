<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashflowEntry;
use App\Models\KnowledgeFavorite;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\RealEstate;
use App\Models\RealEstateTenant;
use App\Models\Transaction;
use App\Support\Finance\CashflowStatement;
use App\Support\Knowledge\FinanceNews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class KnowledgeAndCashflowTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_tips_are_filtered_and_can_be_kept_as_favourites(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Volt::test('knowledge.index')
            ->assertSee('Breit gestreuter ETF-Sparplan')
            ->assertSee('keine Steuer-, Rechts- oder Anlageberatung')
            ->set('category', 'taxes')
            ->assertSee('Günstigerprüfung bei niedrigem Einkommen')
            ->assertDontSee('Breit gestreuter ETF-Sparplan')
            ->set('category', '')
            ->set('search', 'Sondertilgung')
            ->assertSee('Sondertilgungsrecht vereinbaren')
            ->assertDontSee('Homeoffice-Pauschale nutzen')
            ->call('toggleTip', 'special-repayment');

        $this->assertSame(['tip', 'special-repayment', 'financing'], [KnowledgeFavorite::sole()->kind, KnowledgeFavorite::sole()->key, KnowledgeFavorite::sole()->category]);

        $page->set('tab', 'favorites')->assertSee('Favoriten (1)')->assertSee('Sondertilgungsrecht vereinbaren');
        $page->call('toggleTip', 'special-repayment');
        $this->assertSame(0, KnowledgeFavorite::count());

        $page->call('toggleTip', 'does-not-exist')->assertNotFound();
    }

    public function test_news_are_loaded_per_topic_and_can_be_kept_as_favourites(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        Http::fake(['news.google.com/*' => Http::response('<?xml version="1.0"?><rss><channel><item><title>Grundfreibetrag steigt 2027</title><link>https://example.com/grundfreibetrag</link><pubDate>Mon, 28 Sep 2026 08:00:00 GMT</pubDate><source url="https://example.com">Tagesschau</source></item></channel></rss>')]);

        $page = Volt::test('knowledge.index')
            ->set('tab', 'news')
            ->assertSee('Grundfreibetrag steigt 2027')
            ->assertSee('Tagesschau')
            ->call('toggleNews', FinanceNews::key('https://example.com/grundfreibetrag'));

        $favorite = KnowledgeFavorite::sole();
        $this->assertSame([KnowledgeFavorite::NEWS, 'https://example.com/grundfreibetrag', 'taxes'], [$favorite->kind, $favorite->url, $favorite->category]);

        // Once stored, the favourite stays readable even when the article left the feed.
        Http::fake(['news.google.com/*' => Http::response('<rss><channel></channel></rss>')]);
        $page->set('tab', 'favorites')->assertSee('Grundfreibetrag steigt 2027')->call('removeFavorite', $favorite->id);
        $this->assertSame(0, KnowledgeFavorite::count());

        $this->withSession(['auth.password_confirmed_at' => time()])->get(route('profile.export'))->assertOk();
    }

    public function test_cashflow_statement_splits_income_after_rich_dad_poor_dad(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'balance' => 10000, 'balance_on' => '2026-09-28']);
        $book = fn (string $on, float $amount, string $category, string $counterparty = 'Arbeitgeber GmbH') => Transaction::factory()->create([
            'bank_account_id' => $account->id, 'booked_on' => $on, 'amount' => $amount, 'category' => $category, 'counterparty' => $counterparty, 'purpose' => 'Buchung',
        ]);

        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $month) {
            $book($month, 3000, 'salary');
            $book($month, -2000, 'groceries', 'Supermarkt');
            $book($month, -500, 'savings', 'Depot');
            $book($month, 800, 'other_income', 'Max Muster');
        }

        $rental = RealEstate::factory()->rental(800)->for($household)->create(['created_by' => $user->id, 'name' => 'Lindenhaus', 'purchase_price' => 300000]);
        $rental->loans()->create(['lender' => 'Bank', 'kind' => 'annuity', 'amount' => 200000, 'interest_rate' => 3, 'repayment_rate' => 2, 'start_on' => '2026-10-01']);
        RealEstateTenant::factory()->create(['real_estate_id' => $rental->id, 'name' => 'Max Muster', 'move_in_on' => '2025-01-01', 'cold_rent' => 800, 'utilities_advance' => 200]);
        RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'name' => 'Eigenheim', 'purchase_price' => 400000]);
        PortfolioTransaction::factory()->create(['portfolio_id' => Portfolio::factory()->create(['user_id' => $user->id])->id, 'type' => 'dividend', 'quantity' => 0, 'price' => 120, 'fees' => 0, 'taxes' => 0, 'executed_on' => '2026-05-15']);

        $statement = CashflowStatement::for($user);

        // Three months of bookings; the tenant's transfers are the rent, not "other income".
        $this->assertSame(3, $statement['months']);
        $this->assertSame([3000.0, 10.0, 800.0, 0.0], [$statement['income']['earned'], $statement['income']['portfolio'], $statement['income']['passive'], $statement['income']['other']]);
        $this->assertSame(2000.0, $statement['expenses']['total']);
        $this->assertSame(1810.0, $statement['cashflow']);
        $this->assertSame(0.405, $statement['freedom']);
        $this->assertSame(1190.0, $statement['gap']);
        $this->assertSame(310000.0, $statement['assets']['total']);
        $this->assertSame(200000.0, $statement['liabilities']['total']);
        $this->assertSame(400000.0, $statement['doodads']['total']);
        $this->assertSame('building', $statement['pattern']);

        $this->actingAs($user);
        Volt::test('cashflow.index')
            ->assertSee('Raus aus dem Hamsterrad')
            ->assertSee('41 %')
            ->assertSee('Auf dem Weg')
            ->assertSee('Eigenheim (selbst bewohnt)')
            ->assertSee('1.810,00 €')
            ->assertSee('gute Schulden');
    }

    public function test_own_entries_complete_or_replace_the_statement(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Volt::test('cashflow.index')
            ->call('add', 'income')->set('entry.label', 'Gehalt Max')->set('entry.amount', '2.000')->call('save')->assertHasNoErrors()
            ->call('add', 'income')->set('entry.category', 'passive')->set('entry.label', 'Lizenz')->set('entry.amount', '50')->call('save')
            ->call('add', 'asset')->set('entry.label', 'ETF-Depot')->set('entry.value', '550.000')->set('entry.amount', '300')->call('save')->assertHasNoErrors()
            ->call('add', 'asset')->set('entry.category', 'other')->set('entry.label', 'Eigenes Auto')->set('entry.value', '20000')->set('entry.productive', false)->call('save')
            ->call('add', 'liability')->set('entry.category', 'car')->set('entry.label', 'Autokredit')->set('entry.value', '8000')->set('entry.amount', '250')->call('save')
            ->call('add', 'expense')->set('entry.label', 'Miete')->set('entry.amount', '1000')->call('save')
            ->call('add', 'expense')->set('entry.label', 'Leer')->call('save')->assertHasErrors('entry.amount');

        $statement = CashflowStatement::for($user);
        $this->assertSame([2000.0, 300.0, 50.0], [$statement['income']['earned'], $statement['income']['portfolio'], $statement['income']['passive']]);
        $this->assertSame(1250.0, $statement['expenses']['total']);
        $this->assertSame(250.0, $statement['payments']);
        $this->assertSame(1100.0, $statement['cashflow']);
        $this->assertSame(['rich_dad' => 542000.0, 'classic' => 562000.0], $statement['net_worth']);
        $this->assertSame(8.4, $statement['wealth_days']);
        $this->assertSame(['E' => 85, 'S' => 0, 'B' => 0, 'I' => 15], array_map(fn (array $quadrant) => $quadrant['share'], $statement['quadrant']));

        $page->assertSee('Ertrag aus ETF-Depot')->assertSee('Rate: Autokredit')->assertSee('schlechte Schulden')->assertSee('Eigenes Auto');

        $rent = CashflowEntry::where('label', 'Miete')->sole();
        $page->call('edit', $rent->id)->set('entry.amount', '1200')->call('save');
        $this->assertSame(1200.0, $rent->fresh()->amount);

        $this->actingAs($this->addMemberTo($user->currentHousehold()));
        Volt::test('cashflow.index')->call('edit', $rent->id)->assertNotFound();
    }

    public function test_finance_categories_can_be_assigned_to_cashflow_roles(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);

        foreach (['2026-08-01', '2026-09-01'] as $month) {
            Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => $month, 'amount' => 3000, 'category' => 'salary', 'counterparty' => 'Arbeitgeber']);
            Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => $month, 'amount' => 400, 'category' => 'other_income', 'counterparty' => 'Lizenzpartner']);
            Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => $month, 'amount' => -1500, 'category' => 'groceries', 'counterparty' => 'Markt']);
            Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => $month, 'amount' => -100, 'category' => 'cash', 'counterparty' => 'Automat']);
        }

        $this->actingAs($user);
        Volt::test('cashflow.index')->assertSee('Werte aus Finanzen')->assertSee('Gehalt (Arbeitseinkommen)')
            ->call('setCategoryRole', 'other_income', 'passive')
            ->call('setCategoryRole', 'cash', 'ignore');

        $statement = CashflowStatement::for($user->fresh());
        $this->assertSame([3000.0, 400.0, 0.0], [$statement['income']['earned'], $statement['income']['passive'], $statement['income']['other']]);
        $this->assertSame(1500.0, $statement['expenses']['total']);
        $this->assertEqualsCanonicalizing(['other_income' => 'passive', 'cash' => 'ignore'], $user->fresh()->cashflow_categories);

        // Back to the default role: the override is removed.
        Volt::test('cashflow.index')->call('setCategoryRole', 'cash', 'expense');
        $this->assertSame(['other_income' => 'passive'], $user->fresh()->cashflow_categories);

        // "Only my own entries": just the categories chosen explicitly come from Finances.
        Volt::test('cashflow.index')->call('toggleManualOnly');
        $statement = CashflowStatement::for($user->fresh());
        $this->assertSame([0.0, 400.0], [$statement['income']['earned'], $statement['income']['passive']]);
        $this->assertSame(0.0, $statement['expenses']['total']);

        Volt::test('cashflow.index')->call('setCategoryRole', 'groceries', 'expense')->call('setCategoryRole', 'nonsense', 'expense')->assertStatus(422);
        $this->assertSame(1500.0, CashflowStatement::for($user->fresh())['expenses']['total']);
    }

    public function test_cashflow_patterns(): void
    {
        $this->assertSame('rich', CashflowStatement::pattern(1.2, 3000, 500, 800000, 100000));
        $this->assertSame('middle', CashflowStatement::pattern(0.1, 100, 200, 50000, 300000));
        $this->assertSame('poor', CashflowStatement::pattern(0, 0, -50, 1000, 0));
        $this->assertSame('building', CashflowStatement::pattern(0.3, 600, 400, 100000, 0));
    }
}
