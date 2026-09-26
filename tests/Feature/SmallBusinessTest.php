<?php

namespace Tests\Feature;

use App\Models\BookkeepingEntry;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Business\EuerReport;
use App\Support\Business\ReceiptAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

    public function test_open_invoices_are_marked_paid_cancelled_and_duplicated(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        $invoice = Invoice::factory()->for($user)->for($household)->create(['recipient' => 'Kunde']);
        $invoice->items()->create(['position' => 1, 'description' => 'Arbeit', 'quantity' => 3, 'unit_price' => 40, 'vat_rate' => 0]);
        $invoice->recalculate();
        $invoice->issue($profile);

        Volt::test('business.index')
            ->assertSee('RE-2026-001')
            ->call('markPaid', $invoice->id, '2026-09-20');
        $this->assertSame(['paid', '2026-09-20'], [$invoice->fresh()->status, $invoice->fresh()->paid_on->toDateString()]);

        Volt::test('business.index')->call('cancel', $invoice->id)->assertStatus(422);
        Volt::test('business.index')->call('markUnpaid', $invoice->id)->call('cancel', $invoice->id);
        $this->assertSame(Invoice::CANCELLED, $invoice->fresh()->status);

        Volt::test('business.index')->call('duplicate', $invoice->id);
        $copy = Invoice::where('user_id', $user->id)->where('status', Invoice::DRAFT)->sole();
        $this->assertNull($copy->number);
        $this->assertSame(120.0, $copy->gross_total);

        Volt::test('business.index')->call('deleteDraft', $invoice->id)->assertStatus(422);
        Volt::test('business.index')->call('deleteDraft', $copy->id);
        $this->assertModelMissing($copy);

        $this->get(route('business.invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_business_data_of_other_members_stays_private(): void
    {
        [$user, $household] = $this->businessOwner();
        $partner = $this->addMemberTo($household);
        $foreign = Invoice::factory()->for($partner)->for($household)->create(['recipient' => 'Geheimkunde', 'status' => Invoice::OPEN, 'number' => 'RE-2026-001']);
        $foreignEntry = BookkeepingEntry::factory()->for($partner)->for($household)->create(['description' => 'Geheime Ausgabe', 'receipt_path' => 'business-receipts/x.pdf', 'receipt_name' => 'x.pdf']);
        Storage::disk('local')->put('business-receipts/x.pdf', 'pdf');

        Volt::test('business.index')->assertDontSee('Geheimkunde');
        Volt::test('business.index')->call('markPaid', $foreign->id)->assertNotFound();
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
        Storage::disk('local')->assertExists($entry->receipt_path);
        $this->get(route('business.receipts.show', $entry))->assertOk();

        Volt::test('business.bookings')->call('deleteEntry', $entry->id);
        Storage::disk('local')->assertMissing($entry->receipt_path);
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
        Storage::disk('local')->put('business-receipts/'.$user->id.'/bon.jpg', 'jpg');
        $entry = BookkeepingEntry::factory()->for($user)->for($household)->create(['receipt_path' => 'business-receipts/'.$user->id.'/bon.jpg']);
        $invoice = Invoice::factory()->for($user)->for($household)->create();

        $user->delete();

        $this->assertModelMissing($entry);
        $this->assertModelMissing($invoice);
        Storage::disk('local')->assertMissing('business-receipts/'.$user->id.'/bon.jpg');
    }
}
