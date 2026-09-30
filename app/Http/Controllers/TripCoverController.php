<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Support\Life\TripCovers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a trip's cover photo only to people who may see the trip.
 */
class TripCoverController extends Controller
{
    public function __invoke(Request $request, Trip $trip): StreamedResponse
    {
        $household = $request->user()->currentHousehold();

        abort_unless($household && Trip::query()->visibleTo($request->user(), $household->id)->whereKey($trip->id)->exists(), 404);
        abort_unless($trip->cover_path && Storage::disk(TripCovers::DISK)->exists($trip->cover_path), 404);

        // URLs carry a version hash of the path, so the browser may cache them for long.
        return Storage::disk(TripCovers::DISK)->response($trip->cover_path, null, ['Cache-Control' => 'private, max-age=2592000']);
    }
}
