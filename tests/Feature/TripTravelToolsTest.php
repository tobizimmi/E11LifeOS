<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripStop;
use App\Support\Life\BookingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TripTravelToolsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private const ECB = '<gesmes:Envelope><Cube><Cube time="2026-09-25"><Cube currency="USD" rate="1.1000"/><Cube currency="THB" rate="38.50"/></Cube></Cube></gesmes:Envelope>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
        Http::preventStrayRequests();
    }

    public function test_a_confirmation_mail_with_reservation_markup_becomes_a_booking(): void
    {
        $html = '<html><body><script type="application/ld+json">'.json_encode([
            '@context' => 'http://schema.org', '@type' => 'FlightReservation', 'reservationNumber' => 'XK7Q2B',
            'reservationFor' => ['@type' => 'Flight', 'flightNumber' => '1234', 'airline' => ['name' => 'Lufthansa', 'iataCode' => 'LH'],
                'departureAirport' => ['iataCode' => 'FRA'], 'arrivalAirport' => ['iataCode' => 'PMI'],
                'departureTime' => '2026-10-10T07:15:00+02:00', 'arrivalTime' => '2026-10-10T09:35:00+02:00'],
        ]).'</script><p>Ihr Flug</p></body></html>';
        $eml = "From: Lufthansa <info@lufthansa.com>\r\nSubject: =?UTF-8?Q?Ihre_Buchungsbest=C3=A4tigung?=\r\nMIME-Version: 1.0\r\n"
            ."Content-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nIhr Flug\r\n"
            ."--b1\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html))."--b1--\r\n";

        $this->assertSame([[
            'kind' => 'flight', 'title' => 'LH1234 FRA → PMI', 'provider' => 'Lufthansa', 'starts_at' => '2026-10-10T07:15', 'ends_at' => '2026-10-10T09:35',
            'reference' => 'XK7Q2B', 'amount' => '', 'currency' => 'EUR',
        ]], BookingMail::read($eml));
    }

    public function test_plain_confirmation_text_is_read_and_taken_over_into_the_trip(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-17']);
        $this->actingAs($user);

        $text = "Buchungsbestätigung Hotel Sol\nGebucht am 20.09.2026\nBuchungsnummer: 4711-ABCD\nAnreise: 10.10.2026 15:00\nAbreise: 17.10.2026 11:00\nGesamtpreis: 1.234,50 €";

        $page = Volt::test('trips.show', ['trip' => $trip])->set('tab', 'bookings')
            ->set('bookingMailText', $text)->call('readBookingMail')
            ->assertSet('mailDrafts.0.kind', 'hotel')
            ->assertSet('mailDrafts.0.reference', '4711-ABCD')
            ->assertSet('mailDrafts.0.starts_at', '2026-10-10T15:00')
            ->assertSet('mailDrafts.0.ends_at', '2026-10-17T11:00')
            ->assertSet('mailDrafts.0.amount', '1234,50')
            ->call('takeMailDraft', 0)
            ->assertSet('mailDrafts', []);

        $booking = TripBooking::sole();
        $this->assertSame(['hotel', '4711-ABCD', 1234.5, null], [$booking->kind, $booking->reference, $booking->cost, $booking->currency]);
        $page->assertSee('4711-ABCD');
    }

    public function test_bookings_in_another_currency_are_converted_with_the_ecb_rate(): void
    {
        Http::fake(['www.ecb.europa.eu/*' => Http::response(self::ECB)]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'currency' => 'THB']);
        $this->actingAs($user);

        $page = Volt::test('trips.show', ['trip' => $trip])
            ->assertSee('1 € = 38,50 THB')
            ->set('tab', 'bookings')
            ->assertSet('booking.currency', 'THB')
            ->set('booking.title', 'Tempeltour')->set('booking.kind', 'activity')->set('booking.cost', '770')
            ->call('saveBooking')
            ->assertHasNoErrors();

        $booking = TripBooking::sole();
        $this->assertSame([20.0, 'THB', 770.0, 38.5], [$booking->cost, $booking->currency, $booking->foreign_amount, $booking->exchange_rate]);
        $page->assertSee('770,00 THB')->assertSee('≈ 20,00 €');

        $page->set('booking.title', 'Unbekannt')->set('booking.cost', '10')->set('booking.currency', 'ISK')->call('saveBooking')->assertHasErrors('booking.cost');
    }

    public function test_the_forecast_is_shown_per_day_of_the_trip(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['daily' => [
            'time' => ['2026-10-01', '2026-10-02'], 'weather_code' => [0, 63], 'temperature_2m_max' => [24.4, 18], 'temperature_2m_min' => [15, 12.6], 'precipitation_probability_max' => [5, 80],
        ]])]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'destination' => null, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);
        TripStop::create(['trip_id' => $trip->id, 'kind' => 'sight', 'title' => 'Hotel', 'lat' => 39.57, 'lng' => 2.65, 'day' => '2026-10-01', 'position' => 1]);
        $this->actingAs($user);

        Volt::test('trips.show', ['trip' => $trip])->set('tab', 'plan')->call('loadWeather')
            ->assertSee('24°/15°')
            ->assertSee('💧80 %');
    }

    public function test_the_trip_can_be_taken_along_as_offline_page_pdf_and_calendar(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$stranger] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Mallorca', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-17']);
        $trip->bookings()->create(['kind' => 'flight', 'title' => 'FRA → PMI', 'starts_at' => '2026-10-10 07:15', 'reference' => 'XK7Q2B']);
        TripStop::create(['trip_id' => $trip->id, 'kind' => 'sight', 'title' => 'Kathedrale', 'day' => '2026-10-11', 'time' => '10:00', 'position' => 1]);

        $this->actingAs($user)->get(route('trips.itinerary', $trip))->assertOk()->assertSee('XK7Q2B')->assertSee('Kathedrale')->assertSee(__('Saved for offline use'));
        $pdf = $this->actingAs($user)->get(route('trips.itinerary', ['trip' => $trip, 'format' => 'pdf']))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        $ics = $this->actingAs($user)->get(route('trips.calendar', $trip))->assertOk()->getContent();
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261010', (string) $ics);
        $this->assertStringContainsString('DTSTART:20261010T071500', (string) $ics);
        $this->assertStringContainsString('SUMMARY:🏛️ Kathedrale', (string) $ics);
        $this->assertStringContainsString('DTSTART:20261011T100000', (string) $ics);

        $this->actingAs($stranger)->get(route('trips.calendar', $trip))->assertNotFound();
    }
}
