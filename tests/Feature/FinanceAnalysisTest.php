<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FinanceRule;
use App\Models\Transaction;
use App\Support\Finance\BankCsvImporter;
use App\Support\Finance\Categorizer;
use App\Support\Finance\FinanceAi;
use App\Support\Finance\FinanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class FinanceAnalysisTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private const SPARKASSE = <<<'CSV'
        "Auftragskonto";"Buchungstag";"Valutadatum";"Buchungstext";"Verwendungszweck";"Lastschrift Ursprungsbetrag";"Beguenstigter/Zahlungspflichtiger";"Kontonummer/IBAN";"Betrag";"Waehrung"
        "DE001";"24.09.26";"24.09.26";"FOLGELASTSCHRIFT";"Netflix Monatsabo";"";"NETFLIX INTERNATIONAL B.V.";"NL00";"-13,99";"EUR"
        "DE001";"23.09.26";"23.09.26";"KARTENZAHLUNG";"REWE Markt GmbH";"";"REWE";"";"-54,20";"EUR"
        "DE001";"01.09.26";"01.09.26";"GEHALT";"Gehalt September";"";"Arbeitgeber GmbH";"";"3.250,00";"EUR"
        CSV;

    private const ING = <<<'CSV'
        Umsatzanzeige;Datei erstellt am: 25.09.2026

        IBAN;DE12 3456
        Saldo;1.234,56;EUR

        Buchung;Valuta;Auftraggeber/Empfänger;Buchungstext;Verwendungszweck;Saldo;Währung;Betrag;Währung
        24.09.2026;24.09.2026;Stadtwerke München;Lastschrift;Abschlag Strom;1.234,56;EUR;-89,00;EUR
        22.09.2026;22.09.2026;Lieferando.de;Lastschrift;Bestellung 123;1.323,56;EUR;-32,50;EUR
        CSV;

    private const DKB = <<<'CSV'
        "Konto";"Girokonto DE12"
        ""
        "Buchungsdatum";"Wertstellung";"Status";"Zahlungspflichtige*r";"Zahlungsempfänger*in";"Verwendungszweck";"Umsatztyp";"IBAN";"Betrag (€)"
        "24.09.26";"24.09.26";"Gebucht";"Max Muster";"Spotify AB";"Premium";"Ausgang";"SE00";"-10,99"
        "20.09.26";"20.09.26";"Gebucht";"Oma Erna";"Max Muster";"Geschenk";"Eingang";"DE00";"50"
        CSV;

    private const N26 = <<<'CSV'
        "Date","Payee","Account number","Transaction type","Payment reference","Amount (EUR)","Amount (Foreign Currency)","Type Foreign Currency","Exchange Rate"
        "2026-09-21","Shell Deutschland","","MasterCard Payment","","-65.40","","",""
        "2026-09-19","ALDI SUED","","MasterCard Payment","","-23.10","","",""
        CSV;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    /**
     * @return array<int, array{0: string, 1: float, 2: string}>
     */
    private function importRows(string $csv, ?BankAccount $account = null): array
    {
        $account ??= BankAccount::factory()->create();
        (new BankCsvImporter)->import($account, $csv, new Categorizer($account->user_id));

        return $account->transactions()->orderBy('booked_on')->orderBy('id')->get()
            ->map(fn (Transaction $transaction) => [$transaction->booked_on->toDateString(), $transaction->amount, $transaction->counterparty.'|'.$transaction->category])
            ->all();
    }

    public function test_statements_of_common_german_banks_are_imported_and_categorized(): void
    {
        $this->assertSame([
            ['2026-09-01', 3250.0, 'Arbeitgeber GmbH|salary'],
            ['2026-09-23', -54.2, 'REWE|groceries'],
            ['2026-09-24', -13.99, 'NETFLIX INTERNATIONAL B.V.|subscriptions'],
        ], $this->importRows(self::SPARKASSE));

        $this->assertSame([
            ['2026-09-22', -32.5, 'Lieferando.de|restaurants'],
            ['2026-09-24', -89.0, 'Stadtwerke München|energy'],
        ], $this->importRows(self::ING));

        // DKB lists payer and payee: the other party depends on the direction.
        $this->assertSame([
            ['2026-09-20', 50.0, 'Oma Erna|other_income'],
            ['2026-09-24', -10.99, 'Spotify AB|subscriptions'],
        ], $this->importRows(self::DKB));

        $this->assertSame([
            ['2026-09-19', -23.1, 'ALDI SUED|groceries'],
            ['2026-09-21', -65.4, 'Shell Deutschland|mobility'],
        ], $this->importRows(self::N26));
    }

    public function test_windows_encoded_files_are_read_and_a_second_import_skips_known_bookings(): void
    {
        $account = BankAccount::factory()->create();
        $importer = new BankCsvImporter;

        $first = $importer->import($account, mb_convert_encoding(self::ING, 'Windows-1252', 'UTF-8'), new Categorizer($account->user_id));
        $second = $importer->import($account, self::ING, new Categorizer($account->user_id));

        $this->assertSame(['imported' => 2, 'skipped' => 0, 'errors' => 0], $first);
        $this->assertSame(['imported' => 0, 'skipped' => 2, 'errors' => 0], $second);
        $this->assertSame('Stadtwerke München', $account->transactions()->where('amount', -89)->value('counterparty'));
    }

    public function test_files_without_date_and_amount_columns_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        (new BankCsvImporter)->import(BankAccount::factory()->create(), "Name;Telefon\nMax;0123", new Categorizer(1));
    }

    public function test_own_rules_win_over_the_built_in_keywords_and_income_categories_need_a_positive_amount(): void
    {
        $account = BankAccount::factory()->create();
        FinanceRule::create(['user_id' => $account->user_id, 'pattern' => 'rewe', 'category' => 'restaurants']);
        $categorizer = new Categorizer($account->user_id);

        $this->assertSame('restaurants', $categorizer->categorize('REWE Markt', null, -20));
        $this->assertSame('groceries', (new Categorizer($account->user_id + 1))->categorize('REWE Markt', null, -20));
        $this->assertSame('salary', $categorizer->categorize('Arbeitgeber', 'Gehalt September', 3000));
        $this->assertSame('other', $categorizer->categorize('Arbeitgeber', 'Gehalt September', -3000));
    }

    public function test_the_report_finds_recurring_payments_price_increases_and_savings(): void
    {
        $account = BankAccount::factory()->create();
        $book = fn (string $date, float $amount, string $payee, string $category) => Transaction::factory()->for($account, 'account')->create([
            'booked_on' => $date, 'amount' => $amount, 'counterparty' => $payee, 'purpose' => null, 'category' => $category,
        ]);

        foreach (['2026-07', '2026-08', '2026-09'] as $index => $month) {
            $book($month.'-01', 3000, 'Arbeitgeber GmbH', 'salary');
            $book($month.'-03', -900, 'Vermieter', 'housing');
            $book($month.'-05', $index === 2 ? -17.99 : -13.99, 'Netflix', 'subscriptions');
            $book($month.'-06', -9.99, 'Disney Plus', 'subscriptions');
            $book($month.'-07', -500, 'Tagesgeld', 'savings');
            $book($month.'-08', -1000, 'Eigenes Konto', 'transfer');
        }

        $report = FinanceReport::build($account->transactions()->get(), Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30'));

        $this->assertSame(9000.0, $report['income']);
        $this->assertSame(2775.94, $report['expenses']);
        $this->assertSame(1500.0, $report['savingsTransfers']);
        $this->assertSame(3, $report['monthCount']);

        $netflix = collect($report['recurring'])->firstWhere('name', 'Netflix');
        $this->assertSame('monthly', $netflix['frequency']);
        $this->assertSame(4.0, $netflix['increase']);
        $this->assertSame(['Vermieter', 'Tagesgeld', 'Netflix', 'Disney Plus'], array_column($report['recurring'], 'name'));

        $titles = array_column($report['suggestions'], 'title');
        $this->assertContains('2 Streamingdienste gleichzeitig', $titles);
        $this->assertContains('Preiserhöhung: Netflix', $titles);
    }

    public function test_a_statement_is_imported_on_the_page_into_a_new_account(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('finance.index')
            ->set('tab', 'import')
            ->set('statement', UploadedFile::fake()->createWithContent('umsaetze.csv', self::SPARKASSE))
            ->call('importStatement')
            ->assertHasErrors('newAccountName')
            ->set('newAccountName', 'Sparkasse Giro')
            ->call('importStatement')
            ->assertHasNoErrors()
            ->assertSet('importResult', ['imported' => 3, 'skipped' => 0, 'errors' => 0])
            ->assertSee('3 Buchungen importiert');

        $account = BankAccount::where('user_id', $user->id)->sole();
        $this->assertSame('Sparkasse Giro', $account->name);

        $this->get(route('finance.index'))->assertOk()->assertSee('Einnahmen und Ausgaben pro Monat')->assertSee('Abos &amp; Streaming', false);
    }

    public function test_a_remembered_category_applies_to_all_bookings_of_the_payee_and_future_imports(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $account = BankAccount::factory()->for($household)->for($user)->create();
        $first = Transaction::factory()->for($account, 'account')->create(['counterparty' => 'Kita Sonnenschein', 'purpose' => 'Beitrag', 'booked_on' => '2026-09-01']);
        $second = Transaction::factory()->for($account, 'account')->create(['counterparty' => 'Kita Sonnenschein', 'purpose' => 'Beitrag', 'booked_on' => '2026-08-01']);

        Volt::test('finance.index')->call('setCategory', $first->id, 'children', true);

        $this->assertSame('children', $first->fresh()->category);
        $this->assertTrue($first->fresh()->category_locked);
        $this->assertSame('children', $second->fresh()->category);
        $this->assertSame('children', (new Categorizer($user->id))->categorize('Kita Sonnenschein', 'Beitrag Oktober', -250));
    }

    public function test_bank_data_of_other_members_stays_private(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $foreignAccount = BankAccount::factory()->for($household)->for($partner)->create(['name' => 'Geheimkonto']);
        $foreign = Transaction::factory()->for($foreignAccount, 'account')->create(['counterparty' => 'Juwelier Glanz', 'booked_on' => '2026-09-10']);
        $this->actingAs($user);

        Volt::test('finance.index')->set('tab', 'transactions')->assertDontSee('Juwelier Glanz')->assertDontSee('Geheimkonto');
        Volt::test('finance.index')->call('setCategory', $foreign->id, 'shopping')->assertNotFound();
        Volt::test('finance.index')->call('deleteAccount', $foreignAccount->id)->assertNotFound();
        $this->get(route('finance.report', ['account' => $foreignAccount->id]))->assertNotFound();
        $this->assertModelExists($foreignAccount);
    }

    public function test_the_analyst_and_the_ai_categorization_use_the_report(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        config(['services.anthropic.key' => 'test-key']);
        $account = BankAccount::factory()->for($household)->for($user)->create();
        $open = Transaction::factory()->for($account, 'account')->create(['counterparty' => 'Tierarzt Dr. Wau', 'booked_on' => '2026-09-10']);

        $this->mock(FinanceAi::class, function (MockInterface $ai) use ($open) {
            $ai->shouldReceive('categorize')->once()->andReturn([$open->id => 'pets']);
            $ai->shouldReceive('advise')->once()->andReturn([
                'summary' => 'Deine Ausgaben sind stabil.',
                'strengths' => ['Keine Dispozinsen'],
                'recommendations' => [['title' => 'Tierversicherung prüfen', 'text' => 'Vergleiche Tarife.', 'yearly_saving' => 120.0]],
            ]);
        });

        Volt::test('finance.index')
            ->call('categorizeWithAi')
            ->call('askAnalyst')
            ->assertSee('Deine Ausgaben sind stabil.')
            ->assertSee('Tierversicherung prüfen');

        $this->assertSame('pets', $open->fresh()->category);
    }

    public function test_the_report_is_downloaded_as_pdf(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $account = BankAccount::factory()->for($household)->for($user)->create();
        Transaction::factory()->for($account, 'account')->create(['booked_on' => '2026-09-10', 'category' => 'groceries']);

        $this->get(route('finance.report', ['period' => '3m', 'account' => $account->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('finanzbericht-2026-07-2026-09.pdf');
    }
}
