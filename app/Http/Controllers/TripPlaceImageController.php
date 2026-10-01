<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripPlace;
use App\Support\Life\TripDiscover;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Redirects to the Wikipedia picture of a place of the trip area (looked up once, then cached).
 */
class TripPlaceImageController extends Controller
{
    public function __invoke(Request $request, Trip $trip, TripPlace $place): RedirectResponse
    {
        $household = $request->user()->currentHousehold();

        abort_unless($place->trip_id === $trip->id && $household && Trip::query()->visibleTo($request->user(), $household->id)->whereKey($trip->id)->exists(), 404);

        $url = isset($place->info['wikipedia']) ? TripDiscover::wikipediaThumbnail($place->info['wikipedia']) : null;

        abort_unless($url !== null, 404);

        return redirect()->away($url)->header('Cache-Control', 'private, max-age=604800');
    }
}
