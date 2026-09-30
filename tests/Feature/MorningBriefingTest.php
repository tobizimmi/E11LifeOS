<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\MealPlan;
use App\Notifications\MorningBriefingNotification;
use App\Support\MorningBriefing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class MorningBriefingTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 07:05'));
        Http::fake([
            'geocoding-api.open-meteo.com/*' => fn ($request) => Http::response(str_contains($request->url(), 'K%C3%B6ln')
                ? ['results' => [['name' => 'Köln', 'admin1' => 'Nordrhein-Westfalen', 'latitude' => 50.93333, 'longitude' => 6.95]]]
                : ['generationtime_ms' => 0.3]),
            'api.open-meteo.com/*' => Http::response(['daily' => ['weather_code' => [61], 'temperature_2m_max' => [17.6], 'temperature_2m_min' => [9.2], 'precipitation_probability_max' => [70]]]),
        ]);
    }

    private function task(Collection $tasks, string $title, ?string $due, array $attributes = []): Item
    {
        $task = Item::factory()->todo()->make($attributes + ['household_id' => $tasks->household_id, 'collection_id' => $tasks->id, 'title' => $title]);

        if ($due) {
            $task->setPropertyValue($tasks->systemProperty('due'), $due);
        }

        $task->save();

        return $task;
    }

    public function test_the_place_is_looked_up_and_the_briefing_lists_weather_tasks_dates_and_meals(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('profile.morning-briefing-form')
            ->set('channel', 'email')->set('hour', '6')->set('place', 'Köln')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('place', 'Köln, Nordrhein-Westfalen');
        $this->assertSame(['email', 6, 50.93333, 6.95], [$user->fresh()->morning_briefing, $user->fresh()->morning_briefing_hour, $user->fresh()->weather_latitude, $user->fresh()->weather_longitude]);

        $tasks = Collection::tasksFor($household->id);
        $this->task($tasks, 'Zahnarzt', '2026-09-28', ['created_by' => $user->id, 'due_time' => '09:30']);
        $this->task($tasks, 'Altlast', '2026-09-20', ['created_by' => $user->id]);
        $this->task($tasks, 'Morgen', '2026-09-29', ['created_by' => $user->id]);
        Volt::test('collections.index')->set('name', 'Geburtstage')->set('template', 'projects')->call('create');
        $birthdays = Collection::where('name', 'Geburtstage')->sole();
        $entry = Item::create(['household_id' => $household->id, 'collection_id' => $birthdays->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Oma', 'visibility' => Item::VISIBILITY_SHARED]);
        $entry->setPropertyValue($birthdays->properties->firstWhere('type', 'date'), '2026-09-28');
        $entry->save();
        MealPlan::create(['household_id' => $household->id, 'created_by' => $user->id, 'visibility' => 'shared', 'date' => '2026-09-28', 'meal_type' => 'dinner', 'title' => 'Kürbissuppe']);

        $briefing = MorningBriefing::for($user->fresh());
        $text = collect($briefing['sections'])->flatMap(fn (array $section) => [$section['title'], ...$section['lines']])->implode("\n");

        $this->assertSame('Köln, Nordrhein-Westfalen: 🌧️ Regen, 9–18 °C, Regen 70 %', $briefing['weather']);
        $this->assertStringContainsString('09:30 Zahnarzt', $text);
        $this->assertStringContainsString('1 Aufgabe ist überfällig', $text);
        $this->assertStringNotContainsString('Morgen', $text);
        $this->assertStringContainsString('Oma – Fällig (Geburtstage)', $text);
        $this->assertStringContainsString('Kürbissuppe', $text);

        $this->get(route('dashboard'))->assertSee('Wetter heute');
        Volt::test('dashboard')->call('loadWidgets')->assertSee('9–18 °C');
    }

    public function test_it_is_sent_once_at_the_chosen_hour(): void
    {
        Notification::fake();
        [$user] = $this->memberWithHousehold();
        $user->forceFill(['morning_briefing' => 'email', 'morning_briefing_hour' => 7])->save();

        $this->artisan('briefing:morning')->expectsOutput('Morning briefings sent: 1')->assertSuccessful();
        $this->artisan('briefing:morning')->expectsOutput('Morning briefings sent: 0');
        Notification::assertSentTo($user, MorningBriefingNotification::class, fn (MorningBriefingNotification $notification) => str_contains($notification->toMail($user)->subject, 'Guten Morgen'));

        $this->travelTo(Carbon::parse('2026-09-29 08:05'));
        $this->artisan('briefing:morning')->expectsOutput('Morning briefings sent: 0');
    }

    public function test_an_unknown_place_is_rejected(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('profile.morning-briefing-form')->set('place', 'Xyzzy')->call('save')->assertHasErrors('place');
        $this->assertNull($user->fresh()->weather_place);
    }
}
