<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BookkeepingEntry;
use App\Models\ExternalLink;
use App\Models\RealEstate;
use App\Models\Transaction;
use App\Notifications\RealEstateReminder;
use App\Support\BrainSearch;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ModuleIntegrationTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday.
        $this->travelTo(Carbon::parse('2026-09-28 07:00'));
    }

    private function property(): array
    {
        [$user, $household] = $this->memberWithHousehold();
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'name' => 'Lindenhaus', 'purchase_price' => 300000]);
        $realEstate->invoices()->create(['vendor' => 'Dachdecker Kurz', 'number' => 'R-77', 'invoice_date' => '2026-09-01', 'due_on' => '2026-09-29', 'amount' => 4200]);
        $realEstate->invoices()->create(['vendor' => 'Alt GmbH', 'invoice_date' => '2026-08-01', 'due_on' => '2026-09-10', 'amount' => 100]);
        $realEstate->subsidies()->create(['program' => 'kfw_458', 'title' => 'KfW 458', 'kind' => 'grant', 'status' => 'idea', 'deadline_on' => '2026-10-05']);
        $realEstate->budgetItems()->create(['trade' => 'heating', 'title' => 'Wärmepumpe', 'start_on' => '2026-10-12', 'end_on' => '2026-10-16']);

        return [$user, $household, $realEstate];
    }

    public function test_property_dates_are_in_the_calendar_feed_and_reminded(): void
    {
        [$user] = $this->property();
        $user->forceFill(['calendar_token' => str_repeat('a', 40), 'reminder_emails' => true])->save();

        $ics = $this->get(route('calendar.feed', ['token' => str_repeat('a', 40)]))->assertOk()->getContent();
        $this->assertStringContainsString('Rechnung Dachdecker Kurz fällig', $ics);
        $this->assertStringContainsString('Frist KfW 458', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261012', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261017', $ics);

        Notification::fake();
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->once()->andReturn(1));
        $this->artisan('real-estate:reminders')->expectsOutput('Real estate reminders: 1')->assertSuccessful();

        // Due tomorrow, deadline in 7 days, overdue on a Monday – the trade in two weeks not yet.
        Notification::assertSentTo($user, RealEstateReminder::class, fn (RealEstateReminder $notification) => collect($notification->events)->pluck('kind')->sort()->values()->all() === ['invoice', 'invoice', 'subsidy']);
    }

    public function test_search_ai_and_dashboard_know_the_properties_and_taxes(): void
    {
        [$user, $household] = $this->property();
        $this->actingAs($user);

        Volt::test('search-palette')->set('query', 'lindenh')->assertSee('Lindenhaus');
        Volt::test('search-palette')->set('query', 'dachdecker')->assertSee('Dachdecker Kurz');

        $document = collect(BrainSearch::documents('Wie teuer wird unser Haus und was ist noch offen?', $user, $household->id))->firstWhere('key', 'real-estate:overview');
        $this->assertStringContainsString('Immobilie "Lindenhaus"', $document['text']);
        $this->assertStringContainsString('Förderung KfW 458', $document['text']);
        $this->assertNull(collect(BrainSearch::documents('Welche Rezepte mögen wir?', $user, $household->id))->firstWhere('key', 'real-estate:overview'));

        Volt::test('dashboard')->assertSee('Lindenhaus')->assertSee('Steuer 2026');

        // Without the module nothing shows up.
        $household->setAddon('real_estate', false);
        Volt::test('search-palette')->set('query', 'lindenh')->assertDontSee('Lindenhaus');
    }

    public function test_documents_can_be_attached_to_bank_bookings_and_business_receipts(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$other] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $booking = Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => '2026-09-25']);
        $receipt = BookkeepingEntry::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $this->actingAs($user);

        Volt::test('finance.index')->set('tab', 'transactions')->set('documentsFor', $booking->id)->assertSee('Dokumente &amp; E-Mails', false);
        Volt::test('documents-panel', ['type' => 'transaction', 'id' => $booking->id])->call('open', 'mail')->set('mailUrl', 'message://1')->call('addMailLink')->assertHasNoErrors();
        Volt::test('documents-panel', ['type' => 'bookkeeping_entry', 'id' => $receipt->id])->call('open', 'mail')->set('mailUrl', 'message://2')->call('addMailLink')->assertHasNoErrors();
        $this->assertSame(2, ExternalLink::count());

        $this->actingAs($other);
        Volt::test('documents-panel', ['type' => 'transaction', 'id' => $booking->id])->assertNotFound();

        $this->actingAs($user);
        $receipt->delete();
        Volt::test('finance.index')->call('deleteAccount', $account->id);
        $this->assertSame(0, ExternalLink::count());
    }
}
