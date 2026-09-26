<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class CalendarFeedTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    private function task(Collection $tasks, string $title, string $due, array $attributes = [], array $values = []): Item
    {
        $task = Item::factory()->todo()->make($attributes + ['household_id' => $tasks->household_id, 'collection_id' => $tasks->id, 'title' => $title]);

        foreach (['due' => $due] + $values as $key => $value) {
            $task->setPropertyValue($tasks->systemProperty($key), $value);
        }

        $task->save();

        return $task;
    }

    public function test_the_feed_lists_open_tasks_and_dates_with_reminders_the_member_may_see(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $tasks = Collection::tasksFor($household->id);
        $this->actingAs($user);

        $this->task($tasks, 'Müll, Papier; Glas', '2026-09-29', [], ['repeat' => 'weekly']);
        $this->task($tasks, 'Elternabend', '2026-09-30', ['due_time' => '19:30']);
        $this->task($tasks, 'Erledigt', '2026-09-26', ['done' => true]);
        $this->task($tasks, 'Geheim vom Partner', '2026-09-27', ['created_by' => $partner->id, 'visibility' => Item::VISIBILITY_PRIVATE]);

        Volt::test('collections.index')->set('name', 'Verträge')->set('template', 'contracts')->call('create');
        $contracts = Collection::where('name', 'Verträge')->sole();
        $contract = Item::create(['household_id' => $household->id, 'collection_id' => $contracts->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Handyvertrag']);
        $contract->setPropertyValue($contracts->properties->firstWhere('name', 'Kündigen bis'), '2026-10-25');
        $contract->save();

        Volt::test('profile.calendar-feed-form')->call('createLink')->assertSee('webcal://');
        $token = $user->fresh()->calendar_token;
        $this->assertSame(40, strlen($token));

        $ics = $this->get(route('calendar.feed', ['token' => $token]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->getContent();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("SUMMARY:Müll\\, Papier\\; Glas\r\n", $ics);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260929\r\nDTEND;VALUE=DATE:20260930\r\nRRULE:FREQ=WEEKLY", $ics);
        $this->assertStringContainsString("DTSTART:20260930T173000Z\r\nDTEND:20260930T180000Z", $ics);
        $this->assertStringContainsString('Handyvertrag – Kündigen bis', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261025', $ics);
        $this->assertStringNotContainsString('Erledigt', $ics);
        $this->assertStringNotContainsString('Geheim vom Partner', $ics);
    }

    public function test_unknown_renewed_or_switched_off_links_do_not_work(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->get('/calendar/'.str_repeat('a', 40).'.ics')->assertNotFound();

        Volt::test('profile.calendar-feed-form')->call('createLink');
        $old = $user->fresh()->calendar_token;
        Volt::test('profile.calendar-feed-form')->call('createLink');
        $this->get(route('calendar.feed', ['token' => $old]))->assertNotFound();
        $this->get(route('calendar.feed', ['token' => $user->fresh()->calendar_token]))->assertOk();

        $current = $user->fresh()->calendar_token;
        Volt::test('profile.calendar-feed-form')->call('disableLink');
        $this->get(route('calendar.feed', ['token' => $current]))->assertNotFound();
        $this->assertArrayNotHasKey('calendar_token', $user->fresh()->toArray());
    }
}
