<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripPackingItem;
use App\Models\TripPlace;
use App\Models\TripStop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API for trips: the list and a trip's itinerary, places, packing list and bookings for
 * offline viewing, plus editing the itinerary, packing list and bookings on the road.
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
            'stops' => $trip->stops->map(fn (TripStop $stop) => $this->stopData($stop))->all(),
            'places' => $trip->places->map(fn (TripPlace $place) => [
                'id' => $place->id,
                'name' => $place->name,
                'kind' => $place->kind,
                'category' => $place->category,
                'address' => $place->address,
                'lat' => $place->lat,
                'lng' => $place->lng,
            ])->all(),
            'packing' => $trip->packingItems->map(fn (TripPackingItem $item) => $this->packingData($item))->all(),
            'bookings' => $trip->bookings->map(fn (TripBooking $booking) => $this->bookingData($booking))->all(),
        ]);
    }

    // --- Itinerary stops -----------------------------------------------------

    public function storeStop(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($trip);
        $stop = $trip->stops()->create($this->validateStop($request) + ['created_by' => $this->user()->id]);

        return $this->ok($this->stopData($stop), 201);
    }

    public function updateStop(Request $request, TripStop $stop): JsonResponse
    {
        $this->authorizeTripId((int) $stop->trip_id);
        $stop->update($this->validateStop($request, required: false));

        return $this->ok($this->stopData($stop->fresh()));
    }

    public function destroyStop(TripStop $stop): JsonResponse
    {
        $this->authorizeTripId((int) $stop->trip_id);
        $stop->delete();

        return $this->ok(['id' => $stop->id]);
    }

    // --- Packing list --------------------------------------------------------

    public function storePacking(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($trip);
        $item = $trip->packingItems()->create($this->validatePacking($request) + ['owner_id' => $this->user()->id]);

        return $this->ok($this->packingData($item), 201);
    }

    public function updatePacking(Request $request, TripPackingItem $packingItem): JsonResponse
    {
        $this->authorizeTripId((int) $packingItem->trip_id);
        $packingItem->update($this->validatePacking($request, required: false));

        return $this->ok($this->packingData($packingItem->fresh()));
    }

    public function destroyPacking(TripPackingItem $packingItem): JsonResponse
    {
        $this->authorizeTripId((int) $packingItem->trip_id);
        $packingItem->delete();

        return $this->ok(['id' => $packingItem->id]);
    }

    // --- Bookings ------------------------------------------------------------

    public function storeBooking(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($trip);
        $booking = $trip->bookings()->create($this->validateBooking($request));

        return $this->ok($this->bookingData($booking), 201);
    }

    public function updateBooking(Request $request, TripBooking $booking): JsonResponse
    {
        $this->authorizeTripId((int) $booking->trip_id);
        $booking->update($this->validateBooking($request, required: false));

        return $this->ok($this->bookingData($booking->fresh()));
    }

    public function destroyBooking(TripBooking $booking): JsonResponse
    {
        $this->authorizeTripId((int) $booking->trip_id);
        $booking->delete();

        return $this->ok(['id' => $booking->id]);
    }

    // --- Validation ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validateStop(Request $request, bool $required = true): array
    {
        return $request->validate([
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'day' => ['nullable', 'date'],
            'time' => ['nullable', 'string', 'max:10'],
            'end_time' => ['nullable', 'string', 'max:10'],
            'kind' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'done' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePacking(Request $request, bool $required = true): array
    {
        return $request->validate([
            'label' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
            'person' => ['nullable', 'string', 'max:60'],
            'packed' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBooking(Request $request, bool $required = true): array
    {
        return $request->validate([
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'kind' => ['nullable', 'string', 'max:40'],
            'provider' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'paid' => ['sometimes', 'boolean'],
        ]);
    }

    // --- Serializers ---------------------------------------------------------

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

    /**
     * @return array{id: int, day: ?string, time: ?string, end_time: ?string, kind: ?string, title: string, location: ?string, notes: ?string, done: bool}
     */
    private function stopData(TripStop $stop): array
    {
        return [
            'id' => $stop->id,
            'day' => $stop->day?->toDateString(),
            'time' => $stop->time,
            'end_time' => $stop->end_time,
            'kind' => $stop->kind,
            'title' => $stop->title,
            'location' => $stop->location,
            'notes' => $stop->notes,
            'done' => (bool) $stop->done,
        ];
    }

    /**
     * @return array{id: int, label: string, category: ?string, person: ?string, packed: bool}
     */
    private function packingData(TripPackingItem $item): array
    {
        return [
            'id' => $item->id,
            'label' => $item->label,
            'category' => $item->category,
            'person' => $item->person,
            'packed' => (bool) $item->packed,
        ];
    }

    /**
     * @return array{id: int, kind: ?string, title: ?string, provider: ?string, starts_at: ?string, ends_at: ?string, reference: ?string, paid: bool}
     */
    private function bookingData(TripBooking $booking): array
    {
        return [
            'id' => $booking->id,
            'kind' => $booking->kind,
            'title' => $booking->title,
            'provider' => $booking->provider,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
            'reference' => $booking->reference,
            'paid' => (bool) $booking->paid,
        ];
    }

    // --- Authorization -------------------------------------------------------

    private function authorizeTrip(Trip $trip): void
    {
        $this->authorizeTripId($trip->id);
    }

    private function authorizeTripId(int $tripId): void
    {
        abort_unless(
            Trip::query()->visibleTo($this->user(), $this->household()->id)->whereKey($tripId)->exists(),
            404
        );
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
