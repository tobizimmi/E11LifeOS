<?php

namespace Tests\Feature;

use App\Models\CashflowEntry;
use App\Models\SavingTipState;
use App\Support\Finance\SavingTips;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SavingTipsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_compound_growth_of_a_monthly_amount(): void
    {
        $this->assertSame(0.0, SavingTips::compound(0, 10));
        $this->assertSame(12000.0, SavingTips::compound(100, 10, 0.0));
        $this->assertEqualsWithDelta(16387.93, SavingTips::compound(100, 10), 0.01);
    }

    public function test_personal_tips_can_be_done_hidden_and_shown_again(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $user->forceFill(['cashflow_manual_only' => true])->save();
        CashflowEntry::create(['user_id' => $user->id, 'kind' => CashflowEntry::INCOME, 'category' => 'earned', 'label' => 'Gehalt', 'amount' => 3000]);
        CashflowEntry::create(['user_id' => $user->id, 'kind' => CashflowEntry::EXPENSE, 'category' => 'housing', 'label' => 'Miete', 'amount' => 2900]);

        Volt::test('cashflow.index')
            ->assertSee('Deine persönlichen Spartipps')->assertSee('Sparquote erhöhen')->assertSee('Notgroschen aufbauen')
            ->assertSee('Wenn du deinen Überschuss von 100 € pro Monat anlegst')
            ->call('setTipStatus', 'savings-rate', 'done')->assertSee('1 Tipp erledigt oder ausgeblendet')
            ->call('setTipStatus', 'emergency-fund', 'hidden')->assertSee('Alle Tipps erledigt oder ausgeblendet');

        $this->assertSame(2, SavingTipState::where('user_id', $user->id)->count());

        Volt::test('cashflow.index')->call('setTipStatus', 'savings-rate')->assertSee('Sparquote erhöhen');
        $this->assertSame(1, SavingTipState::where('user_id', $user->id)->count());
    }
}
