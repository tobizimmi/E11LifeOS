<?php

namespace Tests\Feature;

use App\Mail\BusinessDocumentMail;
use App\Models\BankAccount;
use App\Models\BookkeepingEntry;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\TimeEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Business\VatReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class BusinessAutomationTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function issuedInvoice(User $user, Household $household, BusinessProfile $profile, float $vatRate = 0, ?Customer $customer = null): Invoice
    {
        $invoice = Invoice::factory()->for($user)->for($household)->create(['recipient' => 'Kunde GmbH', 'customer_id' => $customer?->id, 'small_business' => $profile->small_business]);
        $invoice->items()->create(['position' => 1, 'description' => 'Beratung', 'quantity' => 2, 'unit' => 'Std.', 'unit_price' => 50, 'vat_rate' => $vatRate]);
        $invoice->recalculate();
        $invoice->issue($profile);

        return $invoice->fresh();
    }

    public function test_documents_are_emailed_with_the_pdf_and_dunning_levels_are_tracked(): void
    {
        Mail::fake();
        [$user, $household, $profile] = $this->businessOwner();
        $customer = Customer::factory()->for($user)->for($household)->create(['email' => null]);
        $invoice = $this->issuedInvoice($user, $household, $profile, customer: $customer);

        Volt::test('business.index')
            ->call('openDocument', $invoice->id)
            ->call('composeMail')
            ->assertSet('mail.subject', 'Rechnung RE-2026-001 von '.$profile->company_name)
            ->call('sendMail')
            ->assertHasErrors('mail.to')
            ->set('mail.to', 'kunde@example.com')
            ->call('sendMail')
            ->assertHasNoErrors();

        Mail::assertSent(BusinessDocumentMail::class, fn (BusinessDocumentMail $mail) => $mail->hasTo('kunde@example.com') && $mail->reminderLevel === null);
        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertSame('kunde@example.com', $customer->fresh()->email);

        // Overdue: payment reminder, then the first dunning letter with its fee.
        $this->travel(20)->days();
        $this->assertSame(1, $invoice->fresh()->nextReminderLevel());

        Volt::test('business.index')->call('openDocument', $invoice->id)->call('composeMail', 1)->call('sendMail');
        Mail::assertSent(BusinessDocumentMail::class, fn (BusinessDocumentMail $mail) => $mail->reminderLevel === 1);
        $this->assertSame(1, $invoice->fresh()->reminder_level);

        $this->get(route('business.invoices.pdf', ['invoice' => $invoice, 'reminder' => 2]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Volt::test('business.index')->call('openDocument', $invoice->id)->call('markReminded', 3)->assertStatus(422);
        Volt::test('business.index')->call('openDocument', $invoice->id)->call('markReminded', 2);
        $this->assertSame(2, $invoice->fresh()->reminder_level);
    }

    public function test_customers_open_documents_in_the_portal_and_accept_quotes(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        $quote = Invoice::factory()->for($user)->for($household)->create(['type' => Invoice::QUOTE, 'recipient' => 'Kunde GmbH']);
        $quote->items()->create(['position' => 1, 'description' => 'Neue Website', 'quantity' => 1, 'unit_price' => 900, 'vat_rate' => 0]);
        $quote->recalculate();

        $this->assertNull($quote->public_token);
        $quote->issue($profile);
        $link = Volt::test('business.index')->call('openDocument', $quote->id)->call('portalLink')->effects['returns'][0];
        $token = $quote->fresh()->public_token;
        $this->assertSame(route('documents.public', $token), $link);

        auth()->logout();
        $this->get(route('documents.public', $token))->assertOk()->assertSee('Neue Website')->assertSee($quote->fresh()->number);
        $this->get(route('documents.public.pdf', $token))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post(route('documents.public.accept', $token))->assertRedirect(route('documents.public', $token));
        $this->assertSame(Invoice::ACCEPTED, $quote->fresh()->status);
        $this->post(route('documents.public.accept', $token))->assertStatus(422);

        $this->get(route('documents.public', 'wrongtoken'))->assertNotFound();

        // Drafts are never public, even with a token.
        $draft = Invoice::factory()->for($user)->for($household)->create(['public_token' => 'drafttoken123']);
        $this->get(route('documents.public', $draft->public_token))->assertNotFound();
    }

    public function test_issued_invoices_are_exported_as_xrechnung(): void
    {
        [$user, $household, $profile] = $this->businessOwner(smallBusiness: false);
        $customer = Customer::factory()->for($user)->for($household)->create(['buyer_reference' => '04011000-12345-67']);
        $invoice = $this->issuedInvoice($user, $household, $profile, vatRate: 19, customer: $customer);

        $response = $this->get(route('business.invoices.xrechnung', $invoice))->assertOk();
        $xml = simplexml_load_string($response->getContent());
        $xml->registerXPathNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xml->registerXPathNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');

        $this->assertStringContainsString('xrechnung_3.0', (string) $xml->xpath('//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID')[0]);
        $this->assertSame('RE-2026-001', (string) $xml->xpath('//rsm:ExchangedDocument/ram:ID')[0]);
        $this->assertSame('380', (string) $xml->xpath('//rsm:ExchangedDocument/ram:TypeCode')[0]);
        $this->assertSame('04011000-12345-67', (string) $xml->xpath('//ram:BuyerReference')[0]);
        $this->assertSame('119.00', (string) $xml->xpath('//ram:GrandTotalAmount')[0]);
        $this->assertSame('S', (string) $xml->xpath('//ram:ApplicableTradeTax/ram:CategoryCode')[0]);

        $draft = Invoice::factory()->for($user)->for($household)->create();
        $this->get(route('business.invoices.xrechnung', $draft))->assertNotFound();
        $foreign = $this->issuedInvoice(...$this->businessOwnerFor($household));
        $this->actingAs($user)->get(route('business.invoices.xrechnung', $foreign))->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Household, 2: BusinessProfile}
     */
    private function businessOwnerFor(Household $household): array
    {
        $partner = $this->addMemberTo($household);

        return [$partner, $household, BusinessProfile::factory()->for($partner)->for($household)->create()];
    }

    public function test_recurring_invoices_are_created_issued_and_sent_by_the_scheduler(): void
    {
        Mail::fake();
        [$user, $household, $profile] = $this->businessOwner();
        $customer = Customer::factory()->for($user)->for($household)->create(['email' => 'abo@example.com']);
        $template = RecurringInvoice::factory()->for($user)->for($household)->create([
            'customer_id' => $customer->id, 'next_run_on' => '2026-08-01', 'ends_on' => '2026-10-15', 'auto_send' => true,
        ]);
        $paused = RecurringInvoice::factory()->for($user)->for($household)->create(['active' => false, 'next_run_on' => '2026-09-01']);

        $this->artisan('business:run-recurring')->assertSuccessful();

        // August and September were due; October is next and the last period before ends_on.
        $invoices = Invoice::where('recurring_invoice_id', $template->id)->orderBy('id')->get();
        $this->assertSame(['RE-2026-001', 'RE-2026-002'], $invoices->pluck('number')->all());
        $this->assertSame(['August 2026', 'September 2026'], $invoices->pluck('service_period')->all());
        $this->assertSame(100.0, $invoices->first()->gross_total);
        $this->assertNotNull($invoices->first()->sent_at);
        Mail::assertSent(BusinessDocumentMail::class, 2);
        $this->assertSame('2026-10-01', $template->fresh()->next_run_on->toDateString());
        $this->assertTrue($template->fresh()->active);
        $this->assertSame(0, $paused->invoices()->count());

        $this->travelTo(Carbon::parse('2026-10-01 07:00'));
        $this->artisan('business:run-recurring')->assertSuccessful();
        $this->assertFalse($template->fresh()->active);
        $this->assertSame(3, $template->invoices()->count());
    }

    public function test_recurring_templates_are_made_from_invoices_and_edited(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        $invoice = $this->issuedInvoice($user, $household, $profile);

        Volt::test('business.index')->call('openDocument', $invoice->id)->call('makeRecurring', 'quarterly')->assertRedirect();

        $template = RecurringInvoice::where('user_id', $user->id)->sole();
        $this->assertSame(['quarterly', '2026-12-25', 100.0], [$template->frequency, $template->next_run_on->toDateString(), $template->amount()]);

        Volt::test('business.recurring')->assertSee($template->name)->call('runNow', $template->id);
        $this->assertSame(2, Invoice::where('user_id', $user->id)->count());
        $this->assertSame('2027-03-25', $template->fresh()->next_run_on->toDateString());

        $foreign = RecurringInvoice::factory()->for($this->addMemberTo($household))->for($household)->create(['name' => 'Fremdes Abo']);
        Volt::test('business.recurring')->assertDontSee('Fremdes Abo')->call('runNow', $foreign->id)->assertNotFound();
    }

    public function test_tracked_time_is_billed_as_an_invoice_draft(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        $profile->update(['hourly_rate' => 60]);
        $customer = Customer::factory()->for($user)->for($household)->create();
        $other = Customer::factory()->for($user)->for($household)->create();

        Volt::test('business.time')->set('timerDescription', 'Workshop')->set('timerCustomer', (string) $customer->id)->call('startTimer');
        $running = TimeEntry::where('user_id', $user->id)->sole();
        $this->assertNull($running->ended_at);

        $this->travel(90)->minutes();
        Volt::test('business.time')->call('stopTimer');
        $this->assertSame(90, $running->fresh()->minutes);

        $fixedRate = TimeEntry::factory()->for($user)->for($household)->create(['customer_id' => $customer->id, 'minutes' => 30, 'hourly_rate' => 100]);
        $otherEntry = TimeEntry::factory()->for($user)->for($household)->create(['customer_id' => $other->id]);

        Volt::test('business.time')->set('selected', [(string) $running->id, (string) $otherEntry->id])->call('bill')->assertHasErrors('bill');

        Volt::test('business.time')->set('selected', [(string) $running->id, (string) $fixedRate->id])->call('bill')->assertRedirect();

        $invoice = Invoice::where('user_id', $user->id)->sole();
        $this->assertSame([Invoice::DRAFT, $customer->id, 140.0], [$invoice->status, $invoice->customer_id, $invoice->gross_total]);
        $this->assertSame($invoice->id, $running->fresh()->invoice_id);
        $this->assertNull($otherEntry->fresh()->invoice_id);
    }

    public function test_bank_transactions_are_suggested_and_matched_as_payments(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        $invoice = $this->issuedInvoice($user, $household, $profile);
        $account = BankAccount::factory()->for($user)->for($household)->create();
        $match = Transaction::factory()->create(['bank_account_id' => $account->id, 'amount' => 100, 'booked_on' => '2026-09-24', 'purpose' => 'Danke', 'counterparty' => 'Kunde GmbH']);
        $byNumber = Transaction::factory()->create(['bank_account_id' => $account->id, 'amount' => 40, 'booked_on' => '2026-09-24', 'purpose' => 'Teilzahlung re-2026-001']);
        $unrelated = Transaction::factory()->create(['bank_account_id' => $account->id, 'amount' => 55, 'booked_on' => '2026-09-24', 'purpose' => 'Miete']);

        $component = Volt::test('business.index')->call('openDocument', $invoice->id);
        $matches = $component->viewData('matches')->pluck('id');
        $this->assertEqualsCanonicalizing([$match->id, $byNumber->id], $matches->all());
        $this->assertNotContains($unrelated->id, $matches);

        $component->call('matchTransaction', $match->id);
        $invoice->refresh();
        $this->assertSame([Invoice::PAID, 100.0, $match->id], [$invoice->status, $invoice->paid_total, $invoice->payments()->value('transaction_id')]);

        // A transaction pays only one invoice.
        $second = $this->issuedInvoice($user, $household, $profile);
        Volt::test('business.index')->call('openDocument', $second->id)->call('matchTransaction', $match->id)->assertStatus(422);
    }

    public function test_owners_are_notified_about_newly_overdue_invoices(): void
    {
        [$user, $household, $profile] = $this->businessOwner();
        $invoice = $this->issuedInvoice($user, $household, $profile);
        $invoice->update(['due_on' => '2026-09-24']);

        $this->artisan('business:overdue-notices')->expectsOutputToContain('Overdue invoices notified: 1')->assertSuccessful();

        $invoice->update(['due_on' => '2026-09-20']);
        $this->artisan('business:overdue-notices')->expectsOutputToContain('Overdue invoices notified: 0')->assertSuccessful();

        // After a reminder the owner is notified again once its deadline ran out.
        $invoice->update(['reminder_level' => 1, 'last_reminder_at' => '2026-09-17']);
        $this->artisan('business:overdue-notices')->expectsOutputToContain('Overdue invoices notified: 1')->assertSuccessful();
    }

    public function test_the_vat_advance_return_is_split_by_rate_and_period(): void
    {
        [$user, $household, $profile] = $this->businessOwner(smallBusiness: false);
        $invoice = Invoice::factory()->for($user)->for($household)->create(['small_business' => false]);
        $invoice->items()->create(['position' => 1, 'description' => 'Beratung', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 19]);
        $invoice->items()->create(['position' => 2, 'description' => 'Buch', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 7]);
        $invoice->recalculate();
        $invoice->issue($profile, Carbon::parse('2026-03-01'));
        $invoice->recordPayment(Carbon::parse('2026-04-10'), 1297);
        BookkeepingEntry::factory()->for($user)->for($household)->create(['kind' => BookkeepingEntry::EXPENSE, 'booked_on' => '2026-05-02', 'amount' => 119, 'vat_amount' => 19, 'category' => 'office']);

        $periods = VatReturn::periods($user, $household->id, 2026);
        $this->assertCount(4, $periods);
        $this->assertSame([0.0, 0.0], [$periods[0]['kz81'], $periods[0]['kz83']]);
        $this->assertSame([1000.0, 100.0, 19.0, 178.0], [$periods[1]['kz81'], $periods[1]['kz86'], $periods[1]['kz66'], $periods[1]['kz83']]);

        $this->assertCount(12, VatReturn::periods($user, $household->id, 2026, 'month'));

        Volt::test('business.report')->set('year', 2026)->assertSee('Kz 81')->assertSee('1.000,00 €');
    }

    public function test_dunning_and_time_settings_are_saved(): void
    {
        [$user, $household, $profile] = $this->businessOwner();

        Volt::test('business.settings')
            ->set('form.hourly_rate', '75.5')
            ->set('form.reminder_days', '10')
            ->set('form.reminder_fee_2', '7.50')
            ->call('save')
            ->assertHasNoErrors();

        $profile->refresh();
        $this->assertSame([75.5, 10, 7.5], [(float) $profile->hourly_rate, $profile->reminder_days, $profile->reminderFee(2)]);
    }
}
