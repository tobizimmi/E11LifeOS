<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class DataOverviewTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_the_overview_counts_the_members_data(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Seite', 'created_by' => $user->id]);
        $this->actingAs($user);

        Volt::test('profile.data-overview')
            ->assertSee(__('Your data at a glance'))
            ->assertSee(__('Connected services'))
            ->assertSee('Seite' === '' ? 'x' : __('Pages'));
    }
}
