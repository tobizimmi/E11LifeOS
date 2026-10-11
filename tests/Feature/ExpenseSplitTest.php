<?php

namespace Tests\Feature;

use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\Trip;
use App\Support\Life\ExpenseSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ExpenseSplitTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_expenses_are_split_to_the_cent_equally_by_shares_and_by_amounts(): void
    {
        $this->assertSame([1 => 3.34, 2 => 3.33, 3 => 3.33], ExpenseSplitter::split(10.0, 'equal', [1 => 1, 2 => 1, 3 => 1]));
        $this->assertSame([1 => 60.0, 2 => 30.0], ExpenseSplitter::split(90.0, 'shares', [1 => 2, 2 => 1]));
        $this->assertSame([1 => 12.5, 2 => 7.5], ExpenseSplitter::split(20.0, 'amounts', [1 => 12.5, 2 => 7.5]));

        $this->expectExceptionMessage('Die Beträge ergeben 15,00 statt 20,00.');
        ExpenseSplitter::split(20.0, 'amounts', [1 => 10, 2 => 5]);
    }

    public function test_the_fewest_transfers_settle_all_balances(): void
    {
        $transfers = ExpenseSplitter::settlements([1 => ['balance' => 60.0], 2 => ['balance' => -30.0], 3 => ['balance' => -20.0], 4 => ['balance' => -10.0]]);

        $this->assertSame([['from' => 2, 'to' => 1, 'amount' => 30.0], ['from' => 3, 'to' => 1, 'amount' => 20.0], ['from' => 4, 'to' => 1, 'amount' => 10.0]], $transfers);
    }

    public function test_a_group_is_created_expenses_are_added_and_debts_are_settled(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('splits.index')->set('group.title', 'Skiurlaub')->set('group.people', 'Anna, Ben')->call('create');
        $group = ExpenseGroup::where('title', 'Skiurlaub')->sole();
        $this->assertSame([$user->name, 'Anna', 'Ben'], $group->members->pluck('name')->all());
        [$me, $anna, $ben] = $group->members->all();

        Volt::test('splits.show', ['group' => $group->id])->call('newExpense')
            ->set('expense.title', 'Ferienwohnung')->set('expense.amount', '300,00')->set('expense.paid_by', (string) $me->id)->call('saveExpense')->assertHasNoErrors()
            ->call('newExpense')->set('expense.title', 'Pizza')->set('expense.amount', '45')->set('expense.paid_by', (string) $anna->id)
            ->set('expense.split_mode', 'amounts')->set('participants', [$me->id => true, $anna->id => true, $ben->id => false])
            ->set('values', [$me->id => '20', $anna->id => '25'])->call('saveExpense')->assertHasNoErrors();

        $balances = ExpenseSplitter::balances($group->fresh());
        $this->assertSame(180.0, $balances[$me->id]['balance']);
        $this->assertSame(-80.0, $balances[$anna->id]['balance']);
        $this->assertSame(-100.0, $balances[$ben->id]['balance']);

        Volt::test('splits.show', ['group' => $group->id])->set('tab', 'balances')->assertSee('Ben')->assertSee('100,00 €')
            ->call('settle', $ben->id, $me->id, '100');
        $this->assertSame(0.0, ExpenseSplitter::balances($group->fresh())[$ben->id]['balance']);
        $this->assertCount(1, ExpenseSplitter::settlements(ExpenseSplitter::balances($group->fresh())));
    }

    public function test_amounts_that_do_not_add_up_are_rejected(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $group = ExpenseGroup::factory()->create(['household_id' => $household->id, 'created_by' => $user->id]);
        $a = $group->members()->create(['name' => 'A']);
        $b = $group->members()->create(['name' => 'B']);

        Volt::test('splits.show', ['group' => $group->id])->call('newExpense')->set('expense.title', 'Taxi')->set('expense.amount', '30')
            ->set('expense.split_mode', 'amounts')->set('values', [$a->id => '10', $b->id => '10'])->call('saveExpense')
            ->assertHasErrors(['participants']);
        $this->assertSame(0, $group->expenses()->count());
    }

    public function test_export_share_link_and_privacy(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $group = ExpenseGroup::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'WG-Kasse']);
        $a = $group->members()->create(['name' => 'Anna']);
        $b = $group->members()->create(['name' => 'Ben']);
        $expense = $group->expenses()->create(['paid_by' => $a->id, 'title' => 'Klopapier', 'amount' => 8, 'spent_on' => today(), 'split_mode' => 'equal']);
        ExpenseSplitter::saveShares($expense, [$a->id => 1, $b->id => 1]);

        $csv = $this->actingAs($user)->get(route('splits.export', [$group, 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('Klopapier;Sonstiges;8,00;Anna;;4,00;4,00', $csv);
        $this->get(route('splits.export', [$group, 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        Volt::test('splits.show', ['group' => $group->id])->call('createShareLink');
        $token = $group->fresh()->share_token;
        auth()->logout();
        $this->get(route('splits.public', $token))->assertOk()->assertSee('WG-Kasse')->assertSee('Ben')->assertSee('4,00 €');

        $private = ExpenseGroup::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'visibility' => Item::VISIBILITY_PRIVATE]);
        $this->actingAs($partner)->get(route('splits.show', $private))->assertNotFound();
        $this->get(route('splits.export', [$private, 'csv']))->assertNotFound();
    }

    public function test_a_title_that_looks_like_a_spreadsheet_formula_is_defused_in_the_csv_export(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $group = ExpenseGroup::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'WG-Kasse']);
        $a = $group->members()->create(['name' => 'Anna']);
        $expense = $group->expenses()->create(['paid_by' => $a->id, 'title' => '=cmd|\'/c calc\'!A1', 'amount' => 8, 'spent_on' => today(), 'split_mode' => 'equal']);
        ExpenseSplitter::saveShares($expense, [$a->id => 1]);

        $csv = $this->actingAs($user)->get(route('splits.export', [$group, 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString("'=cmd|'/c calc'!A1", $csv);
        $this->assertStringNotContainsString(';=cmd', $csv);
    }

    public function test_a_trip_offers_to_split_its_costs(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'title' => 'Gardasee', 'travelers' => 'Anna, Ben']);
        $this->actingAs($user);

        $this->get(route('trips.show', $trip))->assertSee(route('splits.index', ['trip' => $trip->id]), false);
        Volt::test('splits.index', ['trip' => (string) $trip->id])->assertSet('group.title', 'Gardasee')->call('create');

        $this->assertSame($trip->id, ExpenseGroup::sole()->trip_id);
        $this->assertSame(3, ExpenseGroup::sole()->members()->count());
    }
}
