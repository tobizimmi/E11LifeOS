<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Notifications\TaskDueNow;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TaskRemindersTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
        $keys = WebPush::generateKeys();
        config(['services.webpush.public_key' => $keys['publicKey'], 'services.webpush.private_key' => $keys['privateKey']]);
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        Notification::fake();
    }

    private function task(Collection $tasks, string $title, ?string $due, ?string $time, array $attributes = [], array $values = []): Item
    {
        $task = Item::factory()->todo()->make($attributes + ['household_id' => $tasks->household_id, 'collection_id' => $tasks->id, 'title' => $title]);
        $task->due_time = $time;

        foreach (($due ? ['due' => $due] : []) + $values as $key => $value) {
            $task->setPropertyValue($tasks->systemProperty($key), $value);
        }

        $task->save();

        return $task;
    }

    public function test_times_can_be_entered_and_changing_the_date_rearms_the_reminder(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);

        Volt::test('tasks.index')->call('addTask', 'Zahnarzt anrufen morgen 18 Uhr');
        $task = Item::sole();
        $this->assertSame(['2026-09-26', '18:00'], [$task->dueDate(), $task->due_time]);

        $other = $this->task($tasks, 'Ohne Datum', null, null);
        Volt::test('tasks.index')->call('showList', 'all')->call('setTime', $other->id, '09:00')->assertSee('📅 Morgen')->assertSee('· 09:00');
        $this->assertSame(['2026-09-26', '09:00'], [$other->fresh()->dueDate(), $other->fresh()->due_time]);

        $task->forceFill(['reminded_at' => now()])->saveQuietly();
        Volt::test('tasks.index')->call('setDue', $task->id, 'next_week');
        $this->assertNull($task->fresh()->reminded_at);

        Volt::test('tasks.index')->call('setDue', $task->id, 'none');
        $this->assertNull($task->fresh()->due_time);
    }

    public function test_a_task_is_reminded_once_at_its_time_by_push_or_else_by_email(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $owner->update(['reminder_emails' => true]);
        PushSubscription::factory()->for($partner)->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/partner']);
        $tasks = Collection::tasksFor($household->id);

        $forPartner = $this->task($tasks, 'Müll rausbringen', '2026-09-25', '09:55', ['created_by' => $owner->id], ['assignee' => $partner->id]);
        $forOwner = $this->task($tasks, 'Arzt anrufen', '2026-09-25', '10:00', ['created_by' => $owner->id]);
        $later = $this->task($tasks, 'Abendessen', '2026-09-25', '18:00', ['created_by' => $owner->id]);
        $tooOld = $this->task($tasks, 'Gestern', '2026-09-24', '08:00', ['created_by' => $owner->id]);

        $this->artisan('reminders:timed')->expectsOutput('Timed reminders: 2')->assertSuccessful();

        $this->assertSame(1, PushMessage::where('user_id', $partner->id)->where('title', '⏰ Müll rausbringen')->count());
        Notification::assertSentTo($owner, TaskDueNow::class, fn (TaskDueNow $notification) => $notification->task->is($forOwner));
        Notification::assertSentTimes(TaskDueNow::class, 1);
        $this->assertNotNull($forPartner->fresh()->reminded_at);
        $this->assertNotNull($tooOld->fresh()->reminded_at);
        $this->assertNull($later->fresh()->reminded_at);

        $this->artisan('reminders:timed')->expectsOutput('Timed reminders: 0');
    }

    public function test_the_new_assignee_gets_a_push_but_not_when_assigning_yourself(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        PushSubscription::factory()->for($partner)->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/partner']);
        PushSubscription::factory()->for($owner)->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/owner']);
        $tasks = Collection::tasksFor($household->id);
        $task = $this->task($tasks, 'Keller aufräumen', null, null, ['created_by' => $owner->id]);
        $this->actingAs($owner);

        Volt::test('tasks.index')->call('setAssignee', $task->id, (string) $partner->id);
        Volt::test('tasks.index')->call('setAssignee', $task->id, (string) $owner->id);

        $message = PushMessage::sole();
        $this->assertSame($partner->id, $message->user_id);
        $this->assertStringContainsString('Keller aufräumen', $message->body);
    }
}
