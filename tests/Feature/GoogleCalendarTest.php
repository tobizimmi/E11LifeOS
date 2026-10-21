<?php

namespace Tests\Feature;

use App\Models\CalendarConnection;
use App\Support\Calendar\GoogleCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class GoogleCalendarTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function configure(): void
    {
        config([
            'services.google_calendar.client_id' => 'client-id',
            'services.google_calendar.client_secret' => 'secret',
            'services.google_calendar.redirect' => 'https://app.test/profile/google-calendar/callback',
        ]);
    }

    public function test_pull_caches_the_upcoming_events(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 09:00'));
        $this->configure();
        [$user] = $this->memberWithHousehold();
        $connection = CalendarConnection::create([
            'user_id' => $user->id, 'provider' => 'google', 'calendar_id' => 'primary',
            'access_token' => 'token', 'token_expires_at' => now()->addHour(),
        ]);

        Http::fake(['*/calendar/v3/*' => Http::response(['items' => [
            ['summary' => 'Zahnarzt', 'start' => ['dateTime' => '2026-10-22T14:30:00+02:00']],
            ['summary' => 'Geburtstag', 'start' => ['date' => '2026-10-25']],
        ]])]);

        $count = GoogleCalendar::pull($connection);

        $this->assertSame(2, $count);
        $events = $connection->fresh()->cached_events;
        $this->assertSame('Zahnarzt', $events[0]['title']);
        $this->assertSame('14:30', $events[0]['time']);
        $this->assertNull($events[1]['time']);
    }

    public function test_connect_is_dormant_without_credentials(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->get(route('google-calendar.connect'))->assertNotFound();
    }

    public function test_connect_redirects_to_google_when_configured(): void
    {
        $this->configure();
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->get(route('google-calendar.connect'))->assertRedirectContains('accounts.google.com');
    }

    public function test_settings_show_the_prepared_notice_without_credentials(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('profile.calendar-sync-form')->assertSee(__('Google Calendar'))->assertSee(__('Prepared, but not yet available: the Google access credentials still need to be set on the server. Once they are, the connect button appears here.'));
    }
}
