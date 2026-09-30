<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\SavingsGoal;
use App\Models\WishlistItem;
use App\Support\Life\Affordability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_wishes_are_checked_against_surplus_and_reserves_in_order_of_priority(): void
    {
        $budget = ['surplus' => 500.0, 'liquid' => 10000.0, 'expenses' => 2000.0, 'reserve' => 6000.0, 'available' => 4000.0, 'has_data' => true];
        $headphones = WishlistItem::factory()->make(['id' => 1, 'price' => 200, 'priority' => 'want', 'reason' => 'Pendeln', 'created_at' => now()->subDays(40)]);
        $sofa = WishlistItem::factory()->make(['id' => 2, 'price' => 3500, 'priority' => 'need', 'reason' => 'Alt', 'created_at' => now()]);
        $bike = WishlistItem::factory()->make(['id' => 3, 'price' => 2500, 'priority' => 'nice', 'created_at' => now()->subDays(10)]);
        $boat = WishlistItem::factory()->make(['id' => 4, 'price' => 20000, 'priority' => 'nice', 'reason' => 'Segeln', 'created_at' => now()->subDays(60)]);

        $checks = Affordability::checkAll(collect([$headphones, $sofa, $bike, $boat]), $budget);

        $this->assertSame('green', $checks[1]['level']);
        $this->assertStringContainsString('aus dem Überschuss dieses Monats', $checks[1]['text']);
        $this->assertSame('green', $checks[2]['level']);
        $this->assertSame('yellow', $checks[3]['level']);
        $this->assertSame(5, $checks[3]['months']);
        $this->assertStringContainsString('30-Tage-Regel: noch 20 Tage warten', $checks[3]['hints'][0]);
        $this->assertSame('red', $checks[4]['level']);
        $this->assertSame('red', Affordability::check($headphones, ['surplus' => -100.0, 'available' => 0.0] + $budget)['level']);
    }

    public function test_a_wish_is_added_turned_into_a_savings_goal_and_decided(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('wishlist.index')->call('create')->set('wish.title', 'Rennrad')->set('wish.price', '1.899,00')
            ->set('wish.reason', 'Mit dem Rad zur Arbeit')->set('wish.priority', 'want')->call('save')->assertHasNoErrors()
            ->assertSee('Rennrad')->assertSee('1.899,00 €')->assertSee('Noch keine Cashflow-Daten');

        $wish = WishlistItem::sole();
        $this->assertSame(1899.0, $wish->price);

        Volt::test('wishlist.index')->call('createSavingsGoal', $wish->id)->assertSee('Sparziel');
        $goal = SavingsGoal::sole();
        $this->assertSame(1899.0, $goal->target_amount);
        $this->assertSame($goal->id, $wish->fresh()->savings_goal_id);

        Volt::test('wishlist.index')->call('decide', $wish->id, 'dropped')->assertSee('Nicht gekauft: 1.899,00 € gespart');
        $this->assertSame('dropped', $wish->fresh()->status);
    }

    public function test_private_wishes_stay_private(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        WishlistItem::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Überraschung', 'visibility' => Item::VISIBILITY_PRIVATE]);
        $shared = WishlistItem::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Staubsauger']);

        $this->actingAs($partner);
        Volt::test('wishlist.index')->assertSee('Staubsauger')->assertDontSee('Überraschung');
        $this->get(route('wishlist.index'))->assertOk();
    }
}
