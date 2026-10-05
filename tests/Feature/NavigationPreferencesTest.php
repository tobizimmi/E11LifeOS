<?php

namespace Tests\Feature;

use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class NavigationPreferencesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_members_pin_and_hide_modules_and_presets_slim_down_the_menu(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk()->assertSee(route('vehicles.index'))->assertSee(__('Customize menu'));

        Volt::test('navigation.settings')
            ->call('togglePinned', 'shopping')
            ->call('toggleHidden', 'vehicles')
            ->call('toggleHidden', 'dashboard')->assertNotFound();

        $this->assertSame(['pinned' => ['shopping'], 'hidden' => ['vehicles']], Navigation::preferences($user->fresh()));
        $this->get(route('dashboard'))->assertOk()->assertSee(__('Pinned'))->assertDontSee(route('vehicles.index').'"', false);
        // Hidden modules still open directly.
        $this->get(route('vehicles.index'))->assertOk();

        // Hiding a pinned module unpins it.
        Volt::test('navigation.settings')->call('toggleHidden', 'shopping');
        $this->assertSame([], Navigation::preferences($user->fresh())['pinned']);

        Volt::test('navigation.settings')->call('applyPreset', 'minimal');
        $hidden = Navigation::preferences($user->fresh())['hidden'];
        $this->assertNotContains('tasks', $hidden);
        $this->assertContains('trips', $hidden);

        Volt::test('navigation.settings')->call('applyPreset', 'everything');
        $this->assertSame([], Navigation::preferences($user->fresh())['hidden']);
    }

    public function test_often_visited_modules_are_suggested(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        foreach (range(1, 5) as $visit) {
            $this->get(route('trips.index'))->assertOk();
        }

        $this->assertSame(['trips'], Navigation::frequent($user));
        $this->get(route('dashboard'))->assertSee(__('Often used'));
    }
}
