<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InAppNotifications;
use App\Support\WebPush;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_pushes_also_land_in_the_bell_without_a_subscribed_device(): void
    {
        [$user] = $this->memberWithHousehold();

        $reached = app(WebPush::class)->send($user, '⏰ Müll rausbringen', 'Jetzt fällig', route('tasks.index'));

        $this->assertSame(0, $reached);
        $this->assertSame(1, InAppNotifications::unreadCount($user));
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Benachrichtigungen (1 ungelesen)');
    }

    public function test_notifications_are_opened_marked_as_read_and_cleared(): void
    {
        [$user] = $this->memberWithHousehold();
        $first = InAppNotifications::record($user, 'Budget überschritten', 'Lebensmittel: 105 %', route('tasks.index'));
        InAppNotifications::record($user, 'Kursalarm', 'ACME über 100 €');
        $this->actingAs($user);

        Volt::test('notifications.index')
            ->assertSee('Budget überschritten')->assertSee('Kursalarm')
            ->call('open', $first->id)
            ->assertRedirect(route('tasks.index'));
        $this->assertSame(1, InAppNotifications::unreadCount($user));

        Volt::test('notifications.index')
            ->set('unreadOnly', true)->assertDontSee('Budget überschritten')->assertSee('Kursalarm')
            ->call('markAllRead')
            ->assertSee('Nichts Ungelesenes.')
            ->call('clearRead');
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_notifications_of_other_members_are_not_reachable(): void
    {
        [$user] = $this->memberWithHousehold();
        $other = User::factory()->create();
        $foreign = InAppNotifications::record($other, 'Geheim');
        $this->actingAs($user);

        $this->get(route('notifications.index'))->assertOk()->assertDontSee('Geheim');
        $this->expectException(ModelNotFoundException::class);
        Volt::test('notifications.index')->call('open', $foreign->id);
    }
}
