<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Life\HabitTracker;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class JournalAndHabitsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-03 12:00'));
    }

    /**
     * Logs a habit as done on the given days back from today.
     *
     * @param  list<int>  $daysAgo
     */
    private function done(Habit $habit, array $daysAgo, int $value = 1): void
    {
        foreach ($daysAgo as $days) {
            HabitLog::create(['habit_id' => $habit->id, 'date' => today()->subDays($days)->toDateString(), 'value' => $value]);
        }
    }

    public function test_habits_are_created_ticked_off_and_counted_for_the_day(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Volt::test('journal.index')
            ->assertSee('Wie war dein Tag?')
            ->call('newHabit', 'water')->assertSet('habitForm.target', '8')
            ->set('habitForm.target', '2')->call('saveHabit')->assertHasNoErrors()
            ->call('newHabit')->set('habitForm.name', 'Lesen')->set('habitForm.schedule', 'weekdays')->set('habitForm.days', [])->call('saveHabit')->assertHasErrors('habitForm.days')
            ->set('habitForm.days', ['6', '7'])->set('habitForm.reminder_at', '20:30')->call('saveHabit')->assertHasNoErrors();

        $water = Habit::where('user_id', $user->id)->where('type', Habit::COUNT)->sole();
        $reading = Habit::where('user_id', $user->id)->where('name', 'Lesen')->sole();
        $this->assertSame([[6, 7], '20:30'], [$reading->days, $reading->reminder_at]);

        // 3 October 2026 is a Saturday, so reading is due; two glasses fulfil the water habit.
        $page->assertSee('Lesen')->call('tapHabit', $water->id)->call('tapHabit', $water->id)->assertDispatched('toast')
            ->call('tapHabit', $reading->id)->call('tapHabit', $reading->id)->call('tapHabit', $reading->id);
        $this->assertSame([2, 1], [HabitLog::where('habit_id', $water->id)->value('value'), HabitLog::where('habit_id', $reading->id)->value('value')]);
        $this->assertFalse($reading->isDueOn(Carbon::parse('2026-10-05')));

        // Someone else's habit cannot be touched.
        $other = Habit::factory()->create();
        $page->call('tapHabit', $other->id)->assertNotFound();

        Volt::test('journal.index', ['tab' => 'habits'])->set('tab', 'habits')->assertSee('Gewohnheitsstärke')->call('archiveHabit', $reading->id);
        $this->assertNotNull($reading->fresh()->archived_at);
        $this->assertSame([$water->id], HabitTracker::active($user)->pluck('id')->all());
    }

    public function test_streaks_and_strength_survive_a_missed_day_and_weekly_habits_count_weeks(): void
    {
        $habit = Habit::factory()->create(['created_at' => today()->subDays(40)]);
        // Done the last 5 days but not today (today does not break the streak yet), missed day 6, done 7–20.
        $this->done($habit, [1, 2, 3, 4, 5, ...range(7, 20)]);
        $stats = HabitTracker::stats($habit, HabitTracker::logs(collect([$habit]))[$habit->id]);

        $this->assertSame([5, 14, 'days'], [$stats['streak'], $stats['best'], $stats['unit']]);
        $this->assertGreaterThan(50, $stats['strength']);
        $this->assertSame(66, $stats['rate']); // 19 of the 29 planned days before today

        // Three times a week: the last two full weeks were reached, this week not yet (does not count).
        $weekly = Habit::factory()->create(['schedule' => Habit::WEEKLY, 'per_week' => 3, 'created_at' => today()->subWeeks(3)]);
        $monday = today()->startOfWeek();
        foreach ([7, 6, 5, 14, 13, 12] as $days) {
            HabitLog::create(['habit_id' => $weekly->id, 'date' => $monday->copy()->subDays($days)->toDateString(), 'value' => 1]);
        }
        $weeklyStats = HabitTracker::stats($weekly, HabitTracker::logs(collect([$weekly]))[$weekly->id]);
        $this->assertSame([2, 'weeks'], [$weeklyStats['streak'], $weeklyStats['unit']]);

        $heatmap = HabitTracker::heatmap($habit, HabitTracker::logs(collect([$habit]))[$habit->id]);
        $this->assertCount(53, $heatmap);
        $cells = collect($heatmap)->flatten(1)->keyBy('date');
        $this->assertSame([4, 0, 0], [$cells[today()->subDay()->toDateString()]['level'], $cells[today()->subDays(6)->toDateString()]['level'], $cells[today()->toDateString()]['level']]);
    }

    public function test_the_journal_saves_mood_activities_encrypted_texts_and_photos(): void
    {
        Storage::fake('local');
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        Item::create(['household_id' => $household->id, 'collection_id' => $tasks->id, 'type' => Item::TYPE_TODO, 'visibility' => Item::VISIBILITY_SHARED, 'title' => 'Steuererklärung abgeben', 'created_by' => $user->id, 'done' => true]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'date' => '2025-10-03', 'mood' => 5, 'highlights' => 'Wanderung auf den Herzogstand']);

        Volt::test('journal.index')
            ->call('setMood', 4)
            ->call('toggleActivity', 'Familie')->set('newActivity', 'Bouldern')->call('addActivity')
            ->set('entry.grateful', 'Sonne und Kaffee')
            ->set('photo', UploadedFile::fake()->image('tag.jpg', 1200, 900))
            ->assertSee('Steuererklärung abgeben')
            ->assertSee('Wanderung auf den Herzogstand')->assertSee('Vor 1 Jahr')
            ->call('setMood', 9)->assertStatus(422);

        $entry = JournalEntry::where('user_id', $user->id)->whereDate('date', '2026-10-03')->sole();
        $this->assertSame([4, ['Familie', 'Bouldern'], 'Sonne und Kaffee'], [$entry->mood, $entry->activities, $entry->grateful]);
        $this->assertStringNotContainsString('Kaffee', (string) DB::table('journal_entries')->where('id', $entry->id)->value('grateful'));
        Storage::disk('local')->assertExists($entry->photos[0]);

        // Photos only for the writer.
        $this->get(route('journal.photo', [$entry, 0]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('journal.photo', [$entry, 0]))->assertNotFound();

        // An entry emptied again is removed together with its photos.
        $this->actingAs($user);
        Volt::test('journal.index')->call('setMood', 4)->call('toggleActivity', 'Familie')->call('toggleActivity', 'Bouldern')->set('entry.grateful', '')->call('deletePhoto', 0);
        $this->assertModelMissing($entry);
        Storage::disk('local')->assertMissing($entry->photos[0]);
    }

    public function test_insights_reminders_dashboard_and_weekly_review(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $sport = Habit::factory()->create(['user_id' => $user->id, 'name' => 'Sport', 'icon' => '🏃', 'reminder_at' => '12:00', 'created_at' => today()->subDays(30)]);

        foreach (range(1, 8) as $days) {
            $sporty = $days % 2 === 0;
            JournalEntry::factory()->create(['user_id' => $user->id, 'date' => today()->subDays($days)->toDateString(), 'mood' => $sporty ? 5 : 3, 'activities' => $sporty ? ['Natur'] : ['Arbeit']]);
            $sporty && $this->done($sport, [$days]);
        }

        $insights = HabitTracker::insights($user);
        $this->assertSame([5.0, 3.0, 2.0], [$insights['habits'][0]['with'], $insights['habits'][0]['without'], $insights['habits'][0]['diff']]);
        $this->assertSame('Natur', $insights['activities'][0]['label']);
        Volt::test('journal.index', ['tab' => 'insights'])->set('tab', 'insights')->assertSee('Stimmung an Tagen mit: Ø 5,0');

        // The push comes at the reminder time only while the habit is open.
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->once()->withArgs(fn (User $to, string $title) => $to->is($user) && str_contains($title, 'Sport')));
        $this->artisan('habits:remind')->assertSuccessful();
        HabitLog::create(['habit_id' => $sport->id, 'date' => today()->toDateString(), 'value' => 1]);
        $this->artisan('habits:remind')->assertSuccessful();

        Volt::test('dashboard')->call('loadWidgets')->assertSee('Gewohnheiten heute')->call('tapHabit', $sport->id);
        $this->assertSame(0, HabitLog::where('habit_id', $sport->id)->whereDate('date', today()->toDateString())->count());

        $lines = HabitTracker::weekLines($user);
        $this->assertStringContainsString('Gewohnheiten erledigt', $lines[0]);
        $this->assertStringContainsString('Stimmung im Schnitt', end($lines));
    }
}
