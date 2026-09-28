<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ExternalLink;
use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\PaperlessInstance;
use App\Models\RealEstate;
use App\Models\RealEstateInvoice;
use App\Models\Transaction;
use App\Support\Finance\FinanceReceiptAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PaperlessInboxTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function fakePaperless(): void
    {
        Http::fake([
            'paperless.test/api/documents/?*' => Http::response(['count' => 2, 'results' => [
                ['id' => 41, 'title' => 'Rechnung Dachdecker Lindenhaus', 'created' => '2026-09-20T00:00:00Z', 'tags' => [1, 3], 'content' => 'Dachdecker Kurz GmbH Rechnung Nr. 77'],
                ['id' => 42, 'title' => 'Kassenbon Baumarkt', 'created' => '2026-09-22T00:00:00Z', 'tags' => [2, 3], 'content' => ''],
            ]]),
            'paperless.test/api/documents/41/preview/' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']),
            'paperless.test/api/documents/42/preview/' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']),
            'paperless.test/api/documents/41/' => Http::response(['id' => 41, 'title' => 'Rechnung Dachdecker Lindenhaus', 'created' => '2026-09-20', 'tags' => [1, 3], 'notes' => []]),
            'paperless.test/api/documents/42/' => Http::response(['id' => 42, 'title' => 'Kassenbon Baumarkt', 'created' => '2026-09-22', 'tags' => [2, 3], 'notes' => []]),
            'paperless.test/api/tags/?*' => Http::response(['results' => [
                ['id' => 1, 'name' => 'Haus', 'is_inbox_tag' => false],
                ['id' => 2, 'name' => 'Beleg', 'is_inbox_tag' => false],
                ['id' => 3, 'name' => 'Posteingang', 'is_inbox_tag' => true],
            ]]),
        ]);
    }

    private function paperlessInstance(int $userId, int $householdId, bool $shared = false): PaperlessInstance
    {
        return PaperlessInstance::create(['user_id' => $userId, 'household_id' => $householdId, 'name' => 'Archiv', 'url' => 'https://paperless.test', 'token' => 'secret-token', 'shared' => $shared]);
    }

    public function test_the_inbox_suggests_a_target_and_files_a_property_invoice(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        $instance = $this->paperlessInstance($user->id, $household->id);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'name' => 'Lindenhaus']);
        $this->actingAs($user);

        $this->get(route('inbox.index'))->assertOk()->assertSeeLivewire('paperless-inbox');

        Volt::test('paperless-inbox')
            ->assertSee('Rechnung Dachdecker Lindenhaus')
            ->assertSee('Ablegen · Immobilien-Rechnung')
            ->assertSee('Ablegen · Private Ausgabe')
            ->call('assign', 41)
            ->assertSet('form.target', 'real_estate')
            ->assertSet('form.real_estate_id', $realEstate->id)
            ->assertSet('form.date', '2026-09-20')
            ->call('save')
            ->assertHasErrors(['form.counterparty', 'form.amount'])
            ->set('form.counterparty', 'Dachdecker Kurz')
            ->set('form.amount', '1.234,50')
            ->assertHasErrors('form.amount')
            ->set('form.amount', '1234,50')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Abgelegt und verknüpft.');

        $invoice = RealEstateInvoice::sole();
        $this->assertSame([$realEstate->id, 'Dachdecker Kurz', 1234.5, '2026-09-20'], [$invoice->real_estate_id, $invoice->vendor, (float) $invoice->amount, $invoice->invoice_date->toDateString()]);
        $link = ExternalLink::sole();
        $this->assertSame(['real_estate_invoice', $invoice->id, $instance->id, 41], [$link->linkable_type, $link->linkable_id, $link->paperless_instance_id, $link->paperless_document_id]);
        // The inbox tag is removed in paperless, the other tags stay.
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/documents/41/') && $request['tags'] === [1]);
    }

    public function test_an_expense_is_prefilled_by_the_ai_and_booked_in_finance(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        $this->paperlessInstance($user->id, $household->id);
        $this->actingAs($user);
        config(['services.anthropic.key' => 'test']);
        $this->mock(FinanceReceiptAi::class, fn (MockInterface $ai) => $ai->shouldReceive('read')->once()->with('%PDF-1.7', 'application/pdf')->andReturn([
            'booked_on' => '2026-09-22', 'counterparty' => 'Baumarkt Nord', 'purpose' => 'Schrauben und Farbe', 'amount' => 37.9, 'category' => 'groceries',
        ]));

        Volt::test('paperless-inbox')
            ->call('assign', 42)
            ->assertSet('form.target', 'finance')
            ->call('prefill')
            ->assertSet('form.counterparty', 'Baumarkt Nord')
            ->assertSet('form.amount', '37,90')
            ->set('form.remove_from_inbox', false)
            ->call('save')
            ->assertHasNoErrors();

        $transaction = Transaction::sole();
        $this->assertSame([-37.9, 'Baumarkt Nord', 'Schrauben und Farbe', $user->id], [(float) $transaction->amount, $transaction->counterparty, $transaction->purpose, $transaction->user_id]);
        $this->assertSame('Bargeld', BankAccount::find($transaction->bank_account_id)->name);
        $this->assertSame(['transaction', $transaction->id], [ExternalLink::sole()->linkable_type, ExternalLink::sole()->linkable_id]);
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    public function test_without_modules_documents_become_tasks_and_access_is_limited(): void
    {
        $this->fakePaperless();
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        HouseholdMember::where('user_id', $partner->id)->update(['permissions' => json_encode(['finance' => false, 'real_estate' => false, 'business' => false])]);
        $personal = $this->paperlessInstance($owner->id, $household->id);
        $shared = $this->paperlessInstance($owner->id, $household->id, shared: true);

        $this->actingAs($partner);
        Volt::test('paperless-inbox')
            ->assertSet('instanceId', $shared->id)
            ->assertDontSee('Private Ausgabe')
            ->set('instanceId', $personal->id)
            ->assertNotFound();

        Volt::test('paperless-inbox')
            ->call('assign', 41)
            ->assertSet('form.target', 'task')
            ->set('form.target', 'finance')
            ->call('save')
            ->assertHasErrors('form.target')
            ->set('form.target', 'task')
            ->set('form.description', 'Dachrechnung prüfen')
            ->call('save')
            ->assertHasNoErrors()
            ->call('dismiss', 42)
            ->assertSee('Aus dem paperless-Posteingang entfernt.');

        $task = Item::where('type', Item::TYPE_TODO)->sole();
        $this->assertSame(['Dachrechnung prüfen', Item::VISIBILITY_SHARED, $partner->id], [$task->title, $task->visibility, $task->created_by]);
        $this->assertSame(['item', $task->id], [ExternalLink::sole()->linkable_type, ExternalLink::sole()->linkable_id]);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/documents/42/') && $request['tags'] === [2]);

        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger)->get(route('inbox.index'))->assertOk()->assertDontSeeLivewire('paperless-inbox');
    }
}
