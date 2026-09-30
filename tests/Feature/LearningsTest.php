<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Learning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class LearningsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 09:00'));
    }

    public function test_reviews_stretch_the_interval_and_again_starts_over(): void
    {
        $learning = Learning::factory()->create(['interval_days' => 1]);

        $learning->review('good');
        $this->assertSame(3, $learning->interval_days);
        $this->assertSame('2026-10-03', $learning->due_on->toDateString());

        $learning->review('easy');
        $this->assertSame(12, $learning->interval_days);

        $learning->review('again');
        $this->assertSame(1, $learning->interval_days);
        $this->assertSame(3, $learning->review_count);

        $learning->update(['interval_days' => 200]);
        $learning->review('easy');
        $this->assertSame(Learning::MAX_INTERVAL, $learning->interval_days);
    }

    public function test_a_lesson_is_written_down_reviewed_and_found_again(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('learnings.index')->call('create')->set('learning.title', 'Nie am selben Tag unterschreiben')
            ->set('learning.body', 'Beim Handyvertrag zu schnell zugesagt.')->set('learning.kind', 'mistake')->set('learning.area', 'money')->set('learning.source', 'Handyladen')
            ->call('save')->assertHasNoErrors()->assertSee('Heute gibt es nichts zu wiederholen');

        $learning = Learning::sole();
        $this->assertSame(Item::VISIBILITY_PRIVATE, $learning->visibility);
        $this->assertSame('2026-10-01', $learning->due_on->toDateString());

        $this->travelTo(Carbon::parse('2026-10-01 08:00'));
        Volt::test('learnings.index')->assertSee('Nie am selben Tag unterschreiben')->assertDontSee('Beim Handyvertrag')
            ->set('revealed', true)->assertSee('Beim Handyvertrag')
            ->call('markApplied', $learning->id)->call('review', $learning->id, 'good')->assertSee('Heute gibt es nichts zu wiederholen');
        $this->assertSame(1, $learning->fresh()->applied_count);
        $this->assertSame('2026-10-04', $learning->fresh()->due_on->toDateString());

        Volt::test('learnings.index')->set('tab', 'library')->set('search', 'handyvertrag')->assertSee('Nie am selben Tag')
            ->set('search', '')->set('area', 'health')->assertDontSee('Nie am selben Tag');
    }

    public function test_private_lessons_stay_private_and_one_year_ago_comes_back(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        Learning::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Mein Geheimnis']);
        Learning::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Gemeinsame Regel', 'visibility' => Item::VISIBILITY_SHARED]);
        Learning::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Alte Lektion', 'created_at' => now()->subYear()]);

        $this->actingAs($user);
        Volt::test('learnings.index')->assertSee('Vor einem Jahr hast du gelernt')->assertSee('Alte Lektion');

        $this->actingAs($partner);
        Volt::test('learnings.index')->set('tab', 'library')->assertSee('Gemeinsame Regel')->assertDontSee('Mein Geheimnis');
    }
}
