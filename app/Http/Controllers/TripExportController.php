<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripStop;
use App\Support\CalendarFeed;
use App\Support\Life\TripItinerary;
use App\Support\Life\TripPlanner;
use App\Support\PdfTheme;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A trip to take along: the travel plan as a page (kept by the app for offline use) or PDF,
 * and bookings plus planned entries as a calendar file.
 */
class TripExportController extends Controller
{
    /**
     * Day by day plan with bookings, codes and the packing list.
     */
    public function itinerary(Request $request, Trip $trip): Response
    {
        $trip = $this->visibleTrip($request, $trip);
        $household = $request->user()->currentHousehold();
        $data = [
            'trip' => $trip,
            'itinerary' => TripItinerary::build($trip),
            'kinds' => TripBooking::kinds(),
            'stopKinds' => TripStop::kinds(),
            'categories' => TripPlanner::categories(),
            'theme' => PdfTheme::for($household?->id, 'general'),
            'offline' => $request->query('format') !== 'pdf',
        ];
        $html = view('trips.itinerary', $data)->render();

        if ($data['offline']) {
            return response(PdfTheme::document($html, $household?->id, false, $data['theme']), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return response(PdfTheme::render($html, $household?->id), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug(__('Travel plan').' '.$trip->title, '-', 'de') ?: 'reiseplan').'.pdf"',
        ]);
    }

    /**
     * Bookings with a time and the planned entries as an ICS file to import into any calendar.
     */
    public function calendar(Request $request, Trip $trip): Response
    {
        $trip = $this->visibleTrip($request, $trip);
        $trip->loadMissing(['bookings', 'stops']);
        $kinds = TripBooking::kinds();
        $stopKinds = TripStop::kinds();
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'e11lifeos';
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//E11LifeOS//Trips//DE', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.CalendarFeed::text($trip->title)];

        $event = function (string $uid, string $summary, string $start, string $end, ?string $location = null, ?string $description = null) use (&$lines, $stamp, $host) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$uid.'@'.$host;
            $lines[] = 'DTSTAMP:'.$stamp;
            $lines[] = $start;
            $lines[] = $end;
            $lines[] = 'SUMMARY:'.CalendarFeed::text($summary);

            if ($location) {
                $lines[] = 'LOCATION:'.CalendarFeed::text($location);
            }

            if ($description) {
                $lines[] = 'DESCRIPTION:'.CalendarFeed::text($description);
            }

            $lines[] = 'END:VEVENT';
        };
        // Floating local times: what was entered is the time at the destination.
        $local = fn (Carbon $time) => $time->format('Ymd\THis');

        $event('trip-'.$trip->id, '🧳 '.$trip->title, 'DTSTART;VALUE=DATE:'.$trip->starts_on->format('Ymd'), 'DTEND;VALUE=DATE:'.$trip->ends_on->copy()->addDay()->format('Ymd'), $trip->destination);

        foreach ($trip->bookings->whereNotNull('starts_at') as $booking) {
            $end = $booking->ends_at && $booking->ends_at->gt($booking->starts_at) && $booking->kind !== 'hotel' ? $booking->ends_at : $booking->starts_at->copy()->addHour();
            $event('trip-booking-'.$booking->id, ($kinds[$booking->kind]['icon'] ?? '📌').' '.$booking->title, 'DTSTART:'.$local($booking->starts_at), 'DTEND:'.$local($end), $booking->provider,
                collect([$booking->provider, $booking->reference ? __('Code :code', ['code' => $booking->reference]) : null])->filter()->implode("\n") ?: null);

            if ($booking->kind === 'hotel' && $booking->ends_at) {
                $event('trip-booking-'.$booking->id.'-out', '🏨 '.__('Check-out').': '.$booking->title, 'DTSTART:'.$local($booking->ends_at), 'DTEND:'.$local($booking->ends_at->copy()->addMinutes(30)), $booking->provider);
            }
        }

        foreach ($trip->stops->whereNotNull('day') as $stop) {
            $icon = $stopKinds[$stop->kind]['icon'] ?? '📍';

            if ($stop->time) {
                $start = Carbon::parse($stop->day->toDateString().' '.$stop->time);
                $end = $stop->end_time ? Carbon::parse($stop->day->toDateString().' '.$stop->end_time) : $start->copy()->addHour();
                $event('trip-stop-'.$stop->id, $icon.' '.$stop->title, 'DTSTART:'.$local($start), 'DTEND:'.$local($end->gt($start) ? $end : $start->copy()->addHour()), $stop->location, $stop->notes);
            } else {
                $event('trip-stop-'.$stop->id, $icon.' '.$stop->title, 'DTSTART;VALUE=DATE:'.$stop->day->format('Ymd'), 'DTEND;VALUE=DATE:'.$stop->day->copy()->addDay()->format('Ymd'), $stop->location, $stop->notes);
            }
        }

        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", array_map(fn (string $line) => CalendarFeed::fold($line), $lines))."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug($trip->title, '-', 'de') ?: 'reise').'.ics"',
        ]);
    }

    private function visibleTrip(Request $request, Trip $trip): Trip
    {
        $household = $request->user()->currentHousehold();

        return Trip::query()->visibleTo($request->user(), (int) $household?->id)->with(['bookings', 'stops', 'packingItems'])->find($trip->id) ?? abort(404);
    }
}
