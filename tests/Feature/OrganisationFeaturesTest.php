<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Notifications\TaskReminder;
use App\Support\Recurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class OrganisationFeaturesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Friday; the week runs Mon 21 – Sun 27 September.
        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    private function task(Collection $tasks, string $title, ?string $due = null, array $values = [], array $attributes = []): Item
    {
        $task = Item::factory()->todo()->create($attributes + ['household_id' => $tasks->household_id, 'title' => $title]);

        if ($due) {
            $task->setPropertyValue($tasks->systemProperty('due'), $due);
        }

        foreach ($values as $key => $value) {
            $task->setPropertyValue($tasks->systemProperty($key), $value);
        }

        $task->save();

        return $task;
    }

    public function test_the_calendar_has_a_week_view_with_previews_of_repeating_tasks(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id);
        $this->task($tasks, 'Blumen gießen', '2026-09-22', ['repeat' => Recurrence::DAILY]);
        $this->task($tasks, 'Zahnarzt', '2026-09-29');
        $this->actingAs($user);

        $component = Volt::test('collections.show', ['collection' => $tasks->id])
            ->set('viewMode', 'calendar')
            ->call('setCalendarRange', 'week')
            ->assertSet('calendarRange', 'week')
            ->assertSee('KW 39')
            ->assertSee('Blumen gießen')
            ->assertDontSee('Zahnarzt');
        $this->assertSame(5, substr_count($component->html(), 'wire:key="preview-2026-09-'));

        $component->call('changeMonth', 1)->assertSet('week', '2026-09-28')->assertSee('Zahnarzt')->assertSee('KW 40');
        $this->assertSame(7, substr_count($component->html(), 'wire:key="preview-'));

        $component->call('setCalendarRange', 'month')->assertSet('month', '2026-09')->assertSet('calendarRange', 'month');

        $this->assertSame(['2026-09-28', '2026-10-28'], Recurrence::occurrencesBetween('2026-08-28', Recurrence::MONTHLY, Carbon::parse('2026-09-21'), Carbon::parse('2026-10-31')));
    }

    public function test_hashtags_become_tags_and_tasks_can_be_filtered_by_tag(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id);
        $this->actingAs($user);

        $this->assertSame(['Milch kaufen', ['einkauf', 'dringend']], Item::splitHashtags('Milch kaufen #Einkauf #dringend'));
        $this->assertSame(['Nur #', []], Item::splitHashtags('Nur #'));

        Volt::test('collections.show', ['collection' => $tasks->id])->set('newTitle', 'Hecke schneiden #garten')->call('addEntry');
        $hecke = Item::where('title', 'Hecke schneiden')->sole();
        $this->assertSame(['garten'], $hecke->tags->pluck('name')->all());

        $steuer = $this->task($tasks, 'Steuererklärung');
        Volt::test('collections.show', ['collection' => $tasks->id])->call('updateTags', $steuer->id, '#Büro, wichtig');
        $this->assertSame(['büro', 'wichtig'], $steuer->fresh()->tags->pluck('name')->sort()->values()->all());

        Volt::test('collections.show', ['collection' => $tasks->id, 'viewMode' => 'table'])
            ->assertSee('#garten')
            ->set('tag', 'garten')
            ->assertSee('Hecke schneiden')
            ->assertDontSee('Steuererklärung')
            // New entries in a filtered view get the tag, so they stay visible.
            ->set('newTitle', 'Rasen mähen')
            ->call('addEntry')
            ->assertSee('Rasen mähen');
    }

    public function test_quick_capture_turns_hashtags_into_tags_of_the_inbox_entry(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('quick-capture')->set('title', 'Idee für Urlaub #reisen')->call('capture');

        $entry = Item::ofType(Item::TYPE_INBOX)->sole();
        $this->assertSame('Idee für Urlaub', $entry->title);
        $this->assertSame(['reisen'], $entry->tags->pluck('name')->all());
        Volt::test('inbox.index')->assertSee('#reisen');
    }

    public function test_daily_reminders_list_due_and_overdue_tasks_of_people_who_opted_in(): void
    {
        Notification::fake();
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $quiet = $this->addMemberTo($household);
        $user->update(['reminder_emails' => true]);
        $partner->update(['reminder_emails' => true]);
        $tasks = Collection::tasksFor($household->id);

        $this->task($tasks, 'Überfällig', '2026-09-20', [], ['created_by' => $user->id]);
        $this->task($tasks, 'Heute', '2026-09-25', ['assignee' => $user->id], ['created_by' => $partner->id]);
        $this->task($tasks, 'Morgen', '2026-09-26', [], ['created_by' => $user->id]);
        $this->task($tasks, 'Erledigt', '2026-09-24', [], ['created_by' => $user->id, 'done' => true]);
        $this->task($tasks, 'Für Partner', '2026-09-25', ['assignee' => $partner->id], ['created_by' => $user->id]);
        $this->task($tasks, 'Privat vom Partner', '2026-09-25', [], ['created_by' => $partner->id, 'visibility' => Item::VISIBILITY_PRIVATE]);
        $this->task($tasks, 'Für Stille', '2026-09-25', ['assignee' => $quiet->id], ['created_by' => $user->id]);

        $this->artisan('reminders:send')->expectsOutput('Reminders sent: 2, pushed: 0')->assertSuccessful();

        Notification::assertSentTo($user, TaskReminder::class, function (TaskReminder $notification) use ($user) {
            $mail = $notification->toMail($user);

            return $notification->tasks->pluck('task.title')->all() === ['Überfällig', 'Heute']
                && str_contains(implode("\n", $mail->introLines), 'Überfällig (fällig 20. Sep)');
        });
        Notification::assertSentTo($partner, TaskReminder::class, fn (TaskReminder $notification) => $notification->tasks->pluck('task.title')->all() === ['Für Partner', 'Privat vom Partner']);
        Notification::assertNotSentTo($quiet, TaskReminder::class);
    }

    public function test_reminders_can_be_switched_on_in_the_profile(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->get(route('profile'))->assertOk()->assertSee('Erinnerungen');
        Volt::test('profile.reminder-settings-form')->set('reminderEmails', true)->assertDispatched('reminders-saved');
        $this->assertTrue($user->fresh()->reminder_emails);
    }

    public function test_the_service_worker_keeps_the_shopping_list_for_offline_use(): void
    {
        $worker = file_get_contents(public_path('service-worker.js'));

        $this->assertStringContainsString("OFFLINE_PAGES = ['shopping-list']", $worker);
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user)->get(route('shopping-list.index'))->assertSee('name="e11-authenticated"', false)->assertSee('Du bist offline');
    }
}
