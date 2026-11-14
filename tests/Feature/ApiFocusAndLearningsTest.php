<?php

namespace Tests\Feature;

use App\Models\FocusEntry;
use App\Models\Item;
use App\Models\Learning;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for focus & time boxing and lessons learned.
 */
class ApiFocusAndLearningsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_the_day_is_planned_with_quadrants_top_tasks_and_time_boxes(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $data = $this->postJson('/api/v1/focus', ['title' => 'Steuer machen', 'also_task' => false], $headers)->assertCreated()->json('data');
        $this->assertCount(1, $data['entries']);
        $this->assertNull($data['entries'][0]['quadrant']);
        $this->assertFalse($data['entries'][0]['linked']);
        $id = $data['entries'][0]['id'];

        // By default a thought becomes a linked to-do as well.
        $data = $this->postJson('/api/v1/focus', ['title' => 'Zahnarzt anrufen'], $headers)->assertCreated()->json('data');
        $linked = collect($data['entries'])->firstWhere('title', 'Zahnarzt anrufen');
        $this->assertTrue($linked['linked']);
        $this->assertSame(1, Item::where('title', 'Zahnarzt anrufen')->count());

        $this->putJson("/api/v1/focus/{$id}", ['quadrant' => 'q2'], $headers)->assertOk();
        $this->putJson("/api/v1/focus/{$id}", ['is_mit' => true], $headers)->assertOk()->assertJsonPath('data.entries.0.is_mit', true);

        // Moving to an unimportant quadrant drops the star.
        $this->putJson("/api/v1/focus/{$id}", ['quadrant' => 'q3'], $headers)->assertOk()->assertJsonPath('data.entries.0.is_mit', false);
        $this->putJson("/api/v1/focus/{$id}", ['quadrant' => 'q1', 'is_mit' => true], $headers)->assertOk()->assertJsonPath('data.entries.0.is_mit', true);
        $this->putJson("/api/v1/focus/{$id}", ['quadrant' => 'q9'], $headers)->assertStatus(422);

        // At most three top tasks per day.
        foreach (['A', 'B', 'C'] as $title) {
            $entry = $this->postJson('/api/v1/focus', ['title' => $title, 'also_task' => false], $headers)->json('data.entries');
            $last = collect($entry)->firstWhere('title', $title)['id'];
            $this->putJson("/api/v1/focus/{$last}", ['quadrant' => 'q1', 'is_mit' => true], $headers)->assertStatus($title === 'C' ? 422 : 200);
        }

        $box = $this->putJson("/api/v1/focus/{$id}", ['start_minute' => 9 * 60 + 30, 'duration_minutes' => 45], $headers)->assertOk()->json('data.entries.0');
        $this->assertSame('09:30–10:15', $box['time_range']);
        $this->putJson("/api/v1/focus/{$id}", ['duration_minutes' => 50], $headers)->assertStatus(422);
        $this->putJson("/api/v1/focus/{$id}", ['start_minute' => null], $headers)->assertOk()->assertJsonPath('data.entries.0.time_range', null);

        // Ticking off syncs the linked to-do.
        $this->putJson("/api/v1/focus/{$linked['id']}", ['done' => true], $headers)->assertOk();
        $this->assertTrue((bool) Item::where('title', 'Zahnarzt anrufen')->value('done'));

        $this->postJson('/api/v1/focus/suggest-top', [], $headers)->assertOk();
        $this->assertLessThanOrEqual(3, FocusEntry::where('is_mit', true)->count());

        $this->deleteJson("/api/v1/focus/{$id}", [], $headers)->assertOk();
        $this->assertNull(FocusEntry::find($id));
        $this->assertSame(1, Item::where('title', 'Zahnarzt anrufen')->count());
    }

    public function test_to_dos_are_imported_and_the_plan_is_personal(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/tasks', ['text' => 'Müll rausbringen'], $headers)->assertCreated();
        $data = $this->postJson('/api/v1/focus/import-tasks', [], $headers)->assertOk()->json('data');
        $this->assertCount(1, $data['entries']);
        $this->postJson('/api/v1/focus/import-tasks', [], $headers)->assertOk()->assertJsonCount(1, 'data.entries');

        $foreign = FocusEntry::create(['household_id' => $household->id, 'user_id' => $partner->id, 'date' => today()->toDateString(), 'title' => 'Privat', 'position' => 1]);
        $this->getJson('/api/v1/focus', $headers)->assertOk()->assertJsonCount(1, 'data.entries');
        $this->putJson("/api/v1/focus/{$foreign->id}", ['done' => true], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/focus/{$foreign->id}", [], $headers)->assertNotFound();
    }

    public function test_lessons_are_saved_reviewed_and_archived(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/learnings', ['title' => ' Früh anfangen ', 'kind' => 'mistake', 'area' => 'work', 'body' => 'Nicht aufschieben'], $headers)
            ->assertCreated()->assertJsonPath('data.title', 'Früh anfangen')->assertJsonPath('data.due', false)->assertJsonPath('data.interval_days', 1)->json('data.id');
        $this->postJson('/api/v1/learnings', ['title' => 'X', 'kind' => 'nope', 'area' => 'work'], $headers)->assertStatus(422);

        // The first review comes the next day.
        Learning::find($id)->update(['due_on' => today()]);
        $list = $this->getJson('/api/v1/learnings', $headers)->assertOk()->json('data');
        $this->assertSame(1, $list['due']);
        $this->assertTrue($list['lessons'][0]['due']);
        $this->assertContains('mistake', array_column($list['kinds'], 'key'));

        $good = $this->postJson("/api/v1/learnings/{$id}/review", ['rating' => 'good'], $headers)->assertOk()->json('data');
        $this->assertGreaterThan(1, $good['interval_days']);
        $this->assertSame(1, $good['review_count']);
        $this->assertFalse($good['due']);
        $this->postJson("/api/v1/learnings/{$id}/review", ['rating' => 'again'], $headers)->assertOk()->assertJsonPath('data.interval_days', 1);
        $this->postJson("/api/v1/learnings/{$id}/review", ['rating' => 'meh'], $headers)->assertStatus(422);

        $this->postJson("/api/v1/learnings/{$id}/applied", [], $headers)->assertOk()->assertJsonPath('data.applied_count', 1);
        $this->putJson("/api/v1/learnings/{$id}", ['title' => 'Früh anfangen', 'kind' => 'principle', 'area' => 'self', 'shared' => false], $headers)->assertOk()->assertJsonPath('data.shared', false);

        // A private lesson is invisible to the partner and to other households.
        $this->getJson('/api/v1/learnings', $this->headersFor($partner))->assertOk()->assertJsonCount(0, 'data.lessons');
        $this->postJson("/api/v1/learnings/{$id}/review", ['rating' => 'good'], $this->headersFor($other))->assertNotFound();
        $this->assertNotNull($otherHousehold);

        $this->postJson("/api/v1/learnings/{$id}/archive", [], $headers)->assertOk()->assertJsonPath('data.archived', true);
        $this->deleteJson("/api/v1/learnings/{$id}", [], $headers)->assertOk();
        $this->assertNull(Learning::find($id));
    }
}
