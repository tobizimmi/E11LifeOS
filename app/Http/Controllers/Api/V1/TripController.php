<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripPackingItem;
use App\Models\TripPlace;
use App\Models\TripStop;
use Illuminate\Http\JsonResponse;

/**
 * Mobile API for trips (read-only for now): the list and a trip's itinerary, places, packing list
 * and bookings, so the app can show a trip offline on the road. Editing trips stays a later phase.
 */
class TripController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok(
            Trip::query()->visibleTo($this->user(), $this->household()->id)
                ->orderByRaw('starts_on IS NULL')->orderBy('starts_on')->get()
                ->map(fn (Trip $trip) => $this->tripData($trip))->all()
        );
    }

    public function show(Trip $trip): JsonResponse
    {
        $this->authorizeTrip($trip);
        $trip->load(['stops', 'places', 'packingItems', 'bookings']);

        return $this->ok($this->tripData($trip) + [
            'notes' => $trip->notes,
            'stops' => $trip->stops->map(fn (TripStop $stop) => [
                'id' => $stop->id,
                'day' => $stop->day,
                'time' => $stop->time,
                'end_time' => $stop->end_time,
                'kind' => $stop->kind,
                'title' => $stop->title,
                'location' => $stop->location,
                'notes' => $stop->notes,
                'done' => (bool) $stop->done,
            ])->all(),
            'places' => $trip->places->map(fn (TripPlace $place) => [
                'id' => $place->id,
                'name' => $place->name,
                'kind' => $place->kind,
                'category' => $place->category,
                'address' => $place->address,
                'lat' => $place->lat,
                'lng' => $place->lng,
            ])->all(),
            'packing' => $trip->packingItems->map(fn (TripPackingItem $item) => [
                'id' => $item->id,
                'label' => $item->label,
                'category' => $item->category,
                'person' => $item->person,
                'packed' => (bool) $item->packed,
            ])->all(),
            'bookings' => $trip->bookings->map(fn (TripBooking $booking) => [
                'id' => $booking->id,
                'kind' => $booking->kind,
                'title' => $booking->title,
                'provider' => $booking->provider,
                'starts_at' => $booking->starts_at?->toIso8601String(),
                'ends_at' => $booking->ends_at?->toIso8601String(),
                'reference' => $booking->reference,
                'paid' => (bool) $booking->paid,
            ])->all(),
        ]);
    }

    /**
     * @return array{id: int, title: string, destination: ?string, starts_on: string, ends_on: string, color: ?string, travelers: ?int}
     */
    private function tripData(Trip $trip): array
    {
        return [
            'id' => $trip->id,
            'title' => $trip->title,
            'destination' => $trip->destination,
            'starts_on' => $trip->starts_on->toDateString(),
            'ends_on' => $trip->ends_on->toDateString(),
            'color' => $trip->color,
            'travelers' => $trip->travelers !== null ? (int) $trip->travelers : null,
        ];
    }

    private function authorizeTrip(Trip $trip): void
    {
        abort_unless(
            Trip::query()->visibleTo($this->user(), $this->household()->id)->whereKey($trip->id)->exists(),
            404
        );
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
