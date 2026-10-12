<?php

namespace Tests\Feature;

use App\Models\BookkeepingEntry;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Support\Business\EuerReport;
use App\Support\Business\ReceiptAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SmallBusinessTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('secure');
        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    /**
     * @return array{0: User, 1: Household, 2: BusinessProfile}
     */
    private function businessOwner(bool $smallBusiness = true): array
    {
        [$user, $household] = $this->memberWithHousehold();
        $profile = BusinessProfile::factory()->for($user)->for($household)->create(['small_business' => $smallBusiness]);
        $this->actingAs($user);

        return [$user, $household, $profile];
    }

    public function test_the_business_profile_is_saved_with_normalized_bank_details(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('business.settings')
            ->set('form.company_name', 'Tobis Holzwerkstatt')
            ->set('form.iban', 'DE12 3456')
            ->call('save')
            ->assertHasErrors('form.iban')
            ->set('form.iban', 'de02 1203 0000 0000 2020 51')
            ->set('form.street', 'Hauptstr. 1')
            ->set('form.city', 'München')
            ->set('form.tax_number', '143/123/45678')
            ->call('save')
            ->assertHasNoErrors();

        $profile = BusinessProfile::where('user_id', $user->id)->sole();
        $this->assertSame('DE02120300000000202051', $profile->iban);
        $this->assertTrue($profile->small_business);
        $this->assertTrue($profile->isCompleteForInvoices());
    }

    public function test_drafts_have_no_number_and_issuing_assigns_consecutive_numbers(): void
    {
        [$user, $household] = $this->businessOwner();
        $customer = Customer::factory()->for($user)->for($household)->create(['name' => 'Bäckerei Korn', 'street' => 'Mühlweg 3', 'postal_code' => '80331', 'city' => 'München']);

        $this->get(route('business.invoices.create', ['customer' => $customer->id]))->assertOk()->assertSee('Bäckerei Korn');

        Volt::test('business.invoice-form')
            ->set('customerId', (string) $customer->id)
            ->assertSet('recipient', "Bäckerei Korn\nMühlweg 3\n80331 München")
            ->set('items.0.description', 'Regal aus Eiche')
            ->set('items.0.quantity', '2')
            ->set('items.0.unit_price', '149,50')
            ->call('addItem')
            ->set('items.1.description', 'Lieferung')
            ->set('items.1.unit_price', '25')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('business.index'));

        $draft = Invoice::where('user_id', $user->id)->sole();
        $this->assertNull($draft->number);
        $this->assertSame(Invoice::DRAFT, $draft->status);
        $this->assertSame(324.0, $draft->gross_total);
        $this->assertSame(0.0, $draft->vat_total);
        $this->assertSame(['Regal aus Eiche', 'Lieferung'], $draft->items->pluck('description')->all());

        Volt::test('business.invoice-form', ['invoice' => $draft])->call('save', true)->assertHasNoErrors();
        Volt::test('business.invoice-form')->set('recipient', 'Max Muster')->set('items.0.description', 'Beratung')->set('items.0.unit_price', '80')->call('save', true);

        $this->assertSame(['RE-2026-001', 'RE-2026-002'], Invoice::where('user_id', $user->id)->orderBy('number')->pluck('number')->all());
        $issued = $draft->fresh();
        $this->assertSame(Invoice::OPEN, $issued->status);
        $this->assertSame('2026-10-09', $issued->due_on->toDateString());

        // Issued invoices can no longer be edited.
        Volt::test('business.invoice-form', ['invoice' => $issued])->assertRedirect(route('business.index'));
    }

    public function test_invoices_with_vat_are_totalled_per_rate(): void
    {
        [$user] = $this->businessOwner(smallBusiness: false);

        Volt::test('business.invoice-form')
            ->set('recipient', 'Kunde GmbH')
            ->set('items.0.description', 'Beratung')
            ->set('items.0.unit_price', '100')
            ->set('items.0.vat_rate', '19')
            ->call('addItem')
            ->set('items.1.description', 'Fachbuch')
            ->set('items.1.unit_price', '20')
            ->set('items.1.vat_rate', '7')
            ->call('save');

        $invoice = Invoice::where('user_id', $user->id)->sole();
        $this->assertSame([120.0, 20.4, 140.4], [$invoice->net_total, $invoice->vat_total, $invoice->gross_total]);
        $this->assertSame([19.0, 7.0], array_column($invoice->load('items')->vatBreakdown(), 'rate'));
    }

    public function test_issuing_needs_complete_business_details(): void
    {
        [$user, , $profile] = $this->businessOwner();
        $profile->update(['tax_number' => null, 'vat_id' => null]);

        Volt::test('business.invoice-form')
            ->set('recipient', 'Kunde')->set('items.0.description', 'Arbeit')->set('items.0.unit_price', '50')
            ->call('save', true)
            ->assertHasErrors('recipient');

        $this->assertSame(0, Invoice::where('user_id', $user->id)->count());
    }

    private function issuedInvoice(User $user, Household $household, BusinessProfile $profile, float $unitPrice = 40, float $quantity = 3): Invoice
    {
        $invoice = Invoice::factory()->for($user)->for($household)->create(['recipient' => 'Kunde']);
        $invoice->items()->create(['position' => 1, 'description' => 'Arbeit', 'quantity' => $quantity, 'unit_price' => $unitPrice, 'vat_rate' => 0]);
        $invoice->recalculate();
        $invoice->issue($profile);

        return $invoice->fresh();
    }

    public function test_invoices_are_paid_in_parts_and_duplicated(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        $invoice = $this->issuedInvoice($user, $household, $profile);

        $component = Volt::test('business.index')
            ->assertSee('RE-2026-001')
            ->call('openDocument', $invoice->id)
            ->assertSet('payment.amount', '120,00')
            ->set('payment.amount', '50,00')
            ->set('payment.paid_on', '2026-09-20')
            ->call('addPayment')
            ->assertHasNoErrors();

        $this->assertSame([Invoice::OPEN, 50.0, 'partial'], [$invoice->fresh()->status, $invoice->fresh()->paid_total, $invoice->fresh()->displayStatus()]);

        $component->assertSet('payment.amount', '70,00')->set('payment.paid_on', '2026-09-24')->call('addPayment');
        $this->assertSame([Invoice::PAID, '2026-09-24'], [$invoice->fresh()->status, $invoice->fresh()->paid_on->toDateString()]);

        // Removing a payment reopens the invoice.
        $component->call('deletePayment', $invoice->payments()->latest('id')->value('id'));
        $this->assertSame(Invoice::OPEN, $invoice->fresh()->status);

        Volt::test('business.index')->call('duplicate', $invoice->id);
        $copy = Invoice::where('user_id', $user->id)->where('status', Invoice::DRAFT)->sole();
        $this->assertNull($copy->number);
        $this->assertSame(120.0, $copy->gross_total);

        Volt::test('business.index')->call('deleteDraft', $invoice->id)->assertStatus(422);
        Volt::test('business.index')->call('deleteDraft', $copy->id);
        $this->assertModelMissing($copy);

        $this->get(route('business.invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_unpaid_invoices_are_cancelled_with_a_cancellation_invoice(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        $invoice = $this->issuedInvoice($user, $household, $profile);
        $paid = $this->issuedInvoice($user, $household, $profile);
        $paid->recordPayment(today(), 10);

        Volt::test('business.index')->call('cancel', $paid->id)->assertHasErrors('cancel');
        Volt::test('business.index')->call('cancel', $invoice->id)->assertHasNoErrors();

        $storno = Invoice::where('type', Invoice::CREDIT_NOTE)->sole();
        $this->assertSame(['GS-2026-001', -120.0, Invoice::CANCELLED, $invoice->id], [$storno->number, $storno->gross_total, $storno->status, $storno->related_invoice_id]);
        $this->assertSame(Invoice::CANCELLED, $invoice->fresh()->status);
        $this->get(route('business.invoices.pdf', $storno))->assertOk();
    }

    public function test_credit_notes_refund_paid_invoices_and_reduce_revenue(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        $invoice = $this->issuedInvoice($user, $household, $profile);
        $invoice->recordPayment(Carbon::parse('2026-09-01'), 120);

        Volt::test('business.index')->call('createCreditNote', $invoice->id);
        $credit = Invoice::where('type', Invoice::CREDIT_NOTE)->sole();
        $this->assertSame([Invoice::DRAFT, -120.0], [$credit->status, $credit->gross_total]);

        Volt::test('business.invoice-form', ['invoice' => $credit])
            ->assertSet('type', Invoice::CREDIT_NOTE)
            ->set('items.0.quantity', '1')
            ->call('save', true);

        $credit->refresh();
        $this->assertSame(['GS-2026-001', -40.0], [$credit->number, $credit->gross_total]);

        Volt::test('business.index')->call('openDocument', $credit->id)->assertSet('payment.amount', '-40,00')->set('payment.paid_on', '2026-09-10')->call('addPayment');
        $this->assertSame(Invoice::PAID, $credit->fresh()->status);

        $report = EuerReport::build($user, $household->id, 2026, $profile);
        $this->assertSame(80.0, $report['lines']['small_business_revenue']);
    }

    public function test_quotes_are_issued_accepted_and_turned_into_invoices(): void
    {
        [$user, $household] = $this->businessOwner();
        $product = Product::factory()->for($user)->for($household)->create(['name' => 'Beratungsstunde', 'unit' => 'Std.', 'unit_price' => 90]);

        $this->get(route('business.invoices.create', ['type' => 'quote']))->assertOk()->assertSee('Neues Angebot');

        Livewire::withQueryParams(['type' => 'quote'])->test('business.invoice-form')
            ->assertSet('type', Invoice::QUOTE)
            ->assertSet('validUntil', '2026-10-25')
            ->set('recipient', 'Café Sonne')
            ->call('addProduct', $product->id)
            ->assertSet('items.0.description', 'Beratungsstunde')
            ->set('items.0.quantity', '10')
            ->set('items.0.discount', '10')
            ->call('save', true)
            ->assertHasNoErrors();

        $quote = Invoice::where('type', Invoice::QUOTE)->sole();
        $this->assertSame(['AN-2026-001', 810.0, Invoice::OPEN], [$quote->number, $quote->gross_total, $quote->status]);
        $this->assertNull($quote->due_on);
        $this->get(route('business.invoices.pdf', $quote))->assertOk();

        Volt::test('business.index')->call('setQuoteStatus', $quote->id, Invoice::ACCEPTED)->call('convertQuote', $quote->id);

        $invoice = Invoice::where('type', Invoice::INVOICE)->sole();
        $this->assertSame([Invoice::DRAFT, 810.0, $quote->id], [$invoice->status, $invoice->gross_total, $invoice->related_invoice_id]);
        $this->assertSame(Invoice::INVOICED, $quote->fresh()->status);
        $this->assertSame(10.0, $invoice->items->first()->discount_percent);
    }

    public function test_the_catalogue_logo_and_number_ranges_are_managed(): void
    {
        [$user, $household, $profile] = $this->businessOwner();

        Volt::test('business.products')
            ->call('newProduct')
            ->set('form.name', 'Website-Wartung')
            ->set('form.unit_price', '1.250,50')
            ->call('saveProduct')
            ->assertHasNoErrors()
            ->assertSee('Website-Wartung');
        $this->assertSame(1250.5, Product::where('user_id', $user->id)->sole()->unit_price);

        Volt::test('business.settings')
            ->set('logo', UploadedFile::fake()->image('logo.png', 200, 80))
            ->assertHasNoErrors()
            ->set('form.quote_prefix', 'RE-')
            ->call('save')
            ->assertHasErrors('form.quote_prefix')
            ->set('form.quote_prefix', 'ANG-')
            ->set('form.accent_color', '#0f766e')
            ->call('save')
            ->assertHasNoErrors();

        $profile->refresh();
        Storage::disk('local')->assertExists($profile->logo_path);
        $this->assertStringStartsWith('data:image/png;base64,', $profile->logoDataUri());

        $quote = Invoice::factory()->for($user)->for($household)->create(['type' => Invoice::QUOTE]);
        $quote->issue($profile);
        $this->assertSame('ANG-2026-001', $quote->number);
    }

    public function test_business_data_of_other_members_stays_private(): void
    {
        [$user, $household] = $this->businessOwner();
        $partner = $this->addMemberTo($household);
        $foreign = Invoice::factory()->for($partner)->for($household)->create(['recipient' => 'Geheimkunde', 'status' => Invoice::OPEN, 'number' => 'RE-2026-001']);
        $foreignEntry = BookkeepingEntry::factory()->for($partner)->for($household)->create(['description' => 'Geheime Ausgabe', 'receipt_path' => 'business-receipts/x.pdf', 'receipt_name' => 'x.pdf']);
        Storage::disk('secure')->put('business-receipts/x.pdf', 'pdf');

        Volt::test('business.index')->assertDontSee('Geheimkunde');
        Volt::test('business.index')->call('openDocument', $foreign->id)->assertNotFound();
        Volt::test('business.bookings')->assertDontSee('Geheime Ausgabe');
        Volt::test('business.bookings')->call('deleteEntry', $foreignEntry->id)->assertNotFound();
        $this->get(route('business.invoices.pdf', $foreign))->assertNotFound();
        $this->get(route('business.invoices.edit', $foreign))->assertNotFound();
        $this->get(route('business.receipts.show', $foreignEntry))->assertNotFound();
    }

    public function test_expenses_are_saved_with_receipt_and_the_ai_reads_receipts(): void
    {
        [$user] = $this->businessOwner();
        config(['services.anthropic.key' => 'test-key']);
        $this->mock(ReceiptAi::class, fn (MockInterface $ai) => $ai->shouldReceive('read')->once()->andReturn([
            'booked_on' => '2026-09-12', 'counterparty' => 'Bauhaus', 'description' => 'Schrauben und Leim', 'amount' => 23.8, 'vat_amount' => 3.8, 'category' => 'goods',
        ]));

        Volt::test('business.bookings')
            ->call('newEntry', 'expense')
            ->call('readReceipt')
            ->assertSet('aiMessage', 'Füge zuerst ein Foto oder PDF des Belegs hinzu.')
            ->set('receipt', UploadedFile::fake()->image('bon.jpg'))
            ->call('readReceipt')
            ->assertSet('form.description', 'Schrauben und Leim')
            ->assertSet('form.amount', '23,80')
            ->call('saveEntry')
            ->assertHasNoErrors()
            ->assertSee('Schrauben und Leim');

        $entry = BookkeepingEntry::where('user_id', $user->id)->sole();
        $this->assertSame(['goods', 23.8, 0.0, '2026-09-12'], [$entry->category, $entry->amount, $entry->vat_amount, $entry->booked_on->toDateString()]);
        Storage::disk('secure')->assertExists($entry->receipt_path);
        $this->get(route('business.receipts.show', $entry))->assertOk();

        Volt::test('business.bookings')->call('deleteEntry', $entry->id);
        Storage::disk('secure')->assertMissing($entry->receipt_path);
    }

    public function test_the_annual_statement_of_a_small_business_follows_the_anlage_euer(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        Invoice::factory()->for($user)->for($household)->paid(1200, '2026-03-10', 'RE-2026-001')->create();
        Invoice::factory()->for($user)->for($household)->paid(800, '2027-01-05', 'RE-2026-002')->create();
        Invoice::factory()->for($user)->for($household)->create(['status' => Invoice::OPEN, 'number' => 'RE-2026-003', 'issued_on' => '2026-09-01', 'gross_total' => 300]);
        $entry = fn (array $attributes) => BookkeepingEntry::factory()->for($user)->for($household)->create($attributes + ['vat_amount' => 0, 'receipt_path' => 'x.jpg']);
        $entry(['kind' => 'income', 'category' => 'sales', 'amount' => 150, 'booked_on' => '2026-05-01']);
        $entry(['category' => 'goods', 'amount' => 238, 'vat_amount' => 38, 'booked_on' => '2026-04-01']);
        $entry(['category' => 'entertainment', 'amount' => 100, 'booked_on' => '2026-06-01']);
        $entry(['category' => 'software', 'amount' => 60, 'booked_on' => '2026-07-01', 'receipt_path' => null]);

        $report = EuerReport::build($user, $household->id, 2026, $profile);

        $this->assertSame(1350.0, $report['lines']['small_business_revenue']);
        $this->assertSame(238.0, $report['lines']['goods']);
        $this->assertSame(70.0, $report['lines']['entertainment']);
        $this->assertSame(60.0, $report['lines']['other_expenses']);
        $this->assertSame(0.0, $report['lines']['vat_paid']);
        $this->assertSame([1350.0, 368.0, 982.0], [$report['incomeTotal'], $report['expenseTotal'], $report['profit']]);
        $this->assertSame([30.0, 1, 1, 300.0], [$report['nonDeductible'], $report['missingReceipts'], $report['openInvoiceCount'], $report['openInvoiceTotal']]);
        $this->assertSame('ok', $report['limitStatus']);

        $csv = $this->get(route('business.report.download', ['year' => 2026, 'format' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('RE-2026-001', $csv);
        $this->assertStringContainsString('Betriebseinnahmen als umsatzsteuerlicher Kleinunternehmer', $csv);
        $this->get(route('business.report.download', ['year' => 2026, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('business.report', ['year' => 2026]))->assertOk()->assertSee('982,00 €');
    }

    public function test_a_counterparty_that_looks_like_a_spreadsheet_formula_is_defused_in_the_csv_export(): void
    {
        [$user, $household] = $this->businessOwner();
        BookkeepingEntry::factory()->for($user)->for($household)->create([
            'category' => 'goods', 'amount' => 10, 'vat_amount' => 0, 'booked_on' => '2026-04-01',
            'counterparty' => '=cmd|\'/c calc\'!A1', 'description' => '+SUM(1+1)', 'receipt_path' => 'x.jpg',
        ]);

        $csv = $this->get(route('business.report.download', ['year' => 2026, 'format' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString("'=cmd|'/c calc'!A1", $csv);
        $this->assertStringContainsString("'+SUM(1+1)", $csv);
        $this->assertStringNotContainsString(';=cmd', $csv);
        $this->assertStringNotContainsString(';+SUM', $csv);
    }

    public function test_with_vat_income_and_expenses_are_split_into_net_and_vat(): void
    {
        [$user, $household, $profile] = $this->businessOwner(smallBusiness: false);
        Invoice::factory()->for($user)->for($household)->paid(1000, '2026-02-01')->create(['small_business' => false, 'vat_total' => 190, 'gross_total' => 1190]);
        BookkeepingEntry::factory()->for($user)->for($household)->create(['category' => 'office', 'amount' => 119, 'vat_amount' => 19, 'booked_on' => '2026-02-02']);
        BookkeepingEntry::factory()->for($user)->for($household)->create(['category' => 'tax_payment', 'amount' => 100, 'vat_amount' => 0, 'booked_on' => '2026-03-10']);

        $report = EuerReport::build($user, $household->id, 2026, $profile);

        $this->assertSame([1000.0, 190.0], [$report['lines']['taxable_revenue'], $report['lines']['vat_received']]);
        $this->assertSame([100.0, 19.0, 100.0], [$report['lines']['other_expenses'], $report['lines']['vat_paid'], $report['lines']['vat_payments']]);
        $this->assertSame(971.0, $report['profit']);
        $this->assertSame(71.0, $report['vat']['balance']);
        $this->assertNull($report['limitStatus']);
    }

    public function test_customers_are_managed_and_invoices_keep_their_address(): void
    {
        [$user, $household] = $this->businessOwner();

        Volt::test('business.customers')
            ->call('newCustomer')
            ->set('form.name', 'Café Sonne')
            ->set('form.city', 'Augsburg')
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertSee('Café Sonne');

        $customer = Customer::where('user_id', $user->id)->sole();
        $invoice = Invoice::factory()->for($user)->for($household)->create(['customer_id' => $customer->id, 'recipient' => 'Café Sonne']);

        Volt::test('business.customers')->call('deleteCustomer', $customer->id);

        $this->assertModelMissing($customer);
        $this->assertSame('Café Sonne', $invoice->fresh()->recipient);
        $this->assertNull($invoice->fresh()->customer_id);
    }

    public function test_deleting_the_account_removes_business_data_and_receipt_files(): void
    {
        [$user, $household] = $this->businessOwner();
        Storage::disk('secure')->put('business-receipts/'.$user->id.'/bon.jpg', 'jpg');
        $entry = BookkeepingEntry::factory()->for($user)->for($household)->create(['receipt_path' => 'business-receipts/'.$user->id.'/bon.jpg']);
        $invoice = Invoice::factory()->for($user)->for($household)->create();

        $user->delete();

        $this->assertModelMissing($entry);
        $this->assertModelMissing($invoice);
        Storage::disk('secure')->assertMissing('business-receipts/'.$user->id.'/bon.jpg');
    }
}
