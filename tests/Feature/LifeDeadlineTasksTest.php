<?php

namespace Tests\Feature;

use App\Models\DeadlineMarker;
use App\Models\EmergencyEntry;
use App\Models\Item;
use App\Support\Life\DeadlineTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class LifeDeadlineTasksTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 09:00'));
    }

    public function test_an_expiring_document_becomes_a_task_once_and_not_again(): void
    {
        [$user] = $this->memberWithHousehold();
        EmergencyEntry::create([
            'user_id' => $user->id,
            'section' => EmergencyEntry::CUSTOM,
            'kind' => 'document',
            'title' => 'Reisepass',
            'expires_on' => today()->addDays(10)->toDateString(),
            'remind_days' => 30,
            'position' => 1,
        ]);

        $this->assertSame(1, DeadlineTasks::createForUser($user));

        $task = Item::where('type', Item::TYPE_TODO)->where('title', 'like', '%Reisepass%')->sole();
        $this->assertStringContainsString('Reisepass', $task->title);
        $this->assertSame(1, DeadlineMarker::where('user_id', $user->id)->count());

        // A second run does not create a duplicate.
        $this->assertSame(0, DeadlineTasks::createForUser($user));
        $this->assertSame(1, Item::where('type', Item::TYPE_TODO)->where('title', 'like', '%Reisepass%')->count());
    }

    public function test_a_document_far_beyond_the_lead_window_is_not_created_yet(): void
    {
        [$user] = $this->memberWithHousehold();
        EmergencyEntry::create([
            'user_id' => $user->id,
            'section' => EmergencyEntry::CUSTOM,
            'kind' => 'document',
            'title' => 'Personalausweis',
            'expires_on' => today()->addDays(120)->toDateString(),
            'remind_days' => 30,
            'position' => 1,
        ]);

        $this->assertSame(0, DeadlineTasks::createForUser($user));
        $this->assertSame(0, Item::where('type', Item::TYPE_TODO)->where('title', 'like', '%Personalausweis%')->count());
    }
}
