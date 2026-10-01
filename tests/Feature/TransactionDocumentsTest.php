<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ExternalLink;
use App\Models\PaperlessInstance;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TransactionDocumentsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array{0: User, 1: Transaction, 2: PaperlessInstance}
     */
    private function setUpBooking(): array
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'name' => 'Giro']);
        $transaction = Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => '2026-09-15', 'amount' => -89.9, 'counterparty' => 'Stadtwerke Lindau', 'purpose' => 'Abschlag Strom', 'category' => 'utilities']);
        $instance = PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://paperless.test', 'token' => 'secret-token', 'shared' => false]);

        Http::fake([
            'paperless.test/api/documents/?*' => Http::response(['count' => 1, 'results' => [['id' => 41, 'title' => 'Rechnung Stadtwerke', 'created' => '2026-09-01T00:00:00Z']]]),
            'paperless.test/api/documents/41/notes/' => Http::response([]),
            'paperless.test/api/documents/41/' => Http::response(['id' => 41, 'title' => 'Rechnung Stadtwerke', 'created' => '2026-09-01', 'tags' => [1], 'notes' => []]),
            'paperless.test/api/documents/post_document/' => Http::response('"task-7"'),
            'paperless.test/api/tags/?*' => Http::response(['results' => [['id' => 1, 'name' => 'Strom']]]),
            'paperless.test/api/tags/' => Http::response(['id' => 9, 'name' => 'Bezahlt'], 201),
        ]);

        return [$user, $transaction, $instance];
    }

    public function test_a_linked_invoice_is_marked_paid_in_paperless(): void
    {
        [$user, $transaction] = $this->setUpBooking();
        $this->actingAs($user);

        $panel = Volt::test('documents-panel', ['type' => 'transaction', 'id' => $transaction->id])
            ->call('open', 'paperless')->assertSet('query', 'Stadtwerke Lindau')->assertSee('Rechnung Stadtwerke')
            ->call('linkPaperless', 41, 'Rechnung Stadtwerke');
        $link = ExternalLink::sole();

        $panel->call('markPaid', $link->id)->assertSee('In paperless als bezahlt markiert.');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/tags/') && $request['name'] === 'Bezahlt');
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/documents/41/') && $request['tags'] === [1, 9]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/documents/41/notes/') && str_contains($request['note'], 'Bezahlt am 15.09.2026: 89,90 € an Stadtwerke Lindau'));
    }

    public function test_a_booking_without_invoice_goes_to_paperless_as_receipt(): void
    {
        [$user, $transaction, $instance] = $this->setUpBooking();
        $this->actingAs($user);

        Volt::test('documents-panel', ['type' => 'transaction', 'id' => $transaction->id])->call('sendBookingToPaperless');

        $link = ExternalLink::sole();
        $this->assertSame(['task-7', $instance->id, 'transaction', $transaction->id], [$link->paperless_task_id, $link->paperless_instance_id, $link->linkable_type, $link->linkable_id]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/documents/post_document/')
            && str_contains($request->body(), 'Buchung 15.09.2026 Stadtwerke Lindau') && str_contains($request->body(), '%PDF') && str_contains($request->body(), '2026-09-15'));
    }

    public function test_bookings_can_be_filtered_by_linked_documents(): void
    {
        [$user, $transaction] = $this->setUpBooking();
        $other = Transaction::factory()->create(['bank_account_id' => $transaction->bank_account_id, 'purpose' => 'Gehalt', 'booked_on' => '2026-09-20', 'amount' => 2500, 'counterparty' => 'Arbeitgeber GmbH', 'category' => 'salary']);
        ExternalLink::create(['household_id' => $transaction->household_id, 'created_by' => $user->id, 'linkable_type' => 'transaction', 'linkable_id' => $other->id, 'kind' => ExternalLink::MAIL, 'url' => 'message://abc', 'title' => 'Gehaltsabrechnung']);
        $this->actingAs($user);

        Volt::test('finance.index')->set('tab', 'transactions')->set('period', 'all')
            ->set('documentFilter', 'with')->assertSee('Arbeitgeber GmbH')->assertDontSee('Stadtwerke Lindau')
            ->set('documentFilter', 'without')->assertSee('Stadtwerke Lindau')->assertDontSee('Arbeitgeber GmbH');
    }
}
