<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\HealthEntry;
use App\Models\HealthProfile;
use App\Models\Item;
use App\Models\Trip;
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
        // No RRULE: the app only ever materialises one concrete next due date per completion,
        // not an infinite series (see CalendarFeed::event()), so the feed must not claim one
        // either — a repeating task still exports as a single, ordinary VEVENT.
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260929\r\nDTEND;VALUE=DATE:20260930\r\n", $ics);
        $this->assertStringNotContainsString('RRULE', $ics);
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

    public function test_the_member_chooses_what_the_feed_contains(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id);
        $this->task($tasks, 'Steuererklärung', '2026-10-02', ['content' => ['body' => 'Belege von der Bank']]);
        Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'destination' => 'Wien', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
        $profile = HealthProfile::factory()->create(['user_id' => $user->id, 'name' => 'Tobi']);
        HealthEntry::factory()->create(['health_profile_id' => $profile->id, 'title' => 'Hautarzt', 'next_due_on' => '2026-10-20']);
        $user->forceFill(['calendar_token' => str_repeat('a', 40)])->save();
        $feed = fn () => $this->get(route('calendar.feed', ['token' => str_repeat('a', 40)]))->assertOk()->getContent();

        $ics = $feed();
        $this->assertStringContainsString('Reise nach Wien beginnt', $ics);
        $this->assertStringContainsString('Belege von der Bank', $ics);
        $this->assertStringNotContainsString('Hautarzt', $ics);

        $this->actingAs($user);
        Volt::test('profile.calendar-feed-form')->set('sections', ['life', 'health'])->set('details', false)->call('saveOptions')->assertHasNoErrors();

        $ics = $feed();
        $this->assertStringContainsString('Hautarzt', $ics);
        $this->assertStringNotContainsString('Steuererklärung', $ics);
        $this->assertStringNotContainsString('URL:', $ics);
    }
}
