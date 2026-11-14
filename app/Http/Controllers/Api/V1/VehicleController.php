<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Support\Life\VehicleStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Vehicles for the app: the cars of the household with their costs, the next dates (inspection,
 * service) and the log of refuelling, workshop visits and trips.
 */
class VehicleController extends ApiController
{
    public function index(): JsonResponse
    {
        $vehicles = Vehicle::query()->visibleTo($this->user(), $this->household()->id)->with('logs')->orderBy('name')->get();

        return $this->ok([
            'fuels' => collect(Vehicle::fuelLabels())->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'vehicles' => $vehicles->map(fn (Vehicle $vehicle) => $this->vehicleData($vehicle))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'make_model' => ['nullable', 'string', 'max:120'],
            'plate' => ['nullable', 'string', 'max:20'],
            'fuel' => ['required', Rule::in(Vehicle::FUELS)],
            'odometer_start' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'private' => ['sometimes', 'boolean'],
        ]);

        $vehicle = Vehicle::create([
            'household_id' => $this->household()->id,
            'created_by' => $this->user()->id,
            'visibility' => ($data['private'] ?? false) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'name' => trim($data['name']),
            'make_model' => trim((string) ($data['make_model'] ?? '')) ?: null,
            'plate' => ($data['plate'] ?? null) ? mb_strtoupper(trim($data['plate'])) : null,
            'fuel' => $data['fuel'],
            'odometer_start' => $data['odometer_start'] ?? null,
        ]);

        return $this->ok($this->vehicleData($vehicle->load('logs'), true), 201);
    }

    public function show(int $vehicle): JsonResponse
    {
        return $this->ok($this->vehicleData($this->find($vehicle), true));
    }

    /**
     * Changes the details that matter on the go: name, plate and the due dates.
     */
    public function update(Request $request, int $vehicle): JsonResponse
    {
        $model = $this->find($vehicle);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'make_model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'plate' => ['sometimes', 'nullable', 'string', 'max:20'],
            'fuel' => ['sometimes', Rule::in(Vehicle::FUELS)],
            'inspection_due' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'service_due' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'service_due_km' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5000000'],
            'tax_yearly' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'insurance_yearly' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);

        if (array_key_exists('plate', $data) && $data['plate'] !== null) {
            $data['plate'] = mb_strtoupper(trim($data['plate'])) ?: null;
        }

        $model->update($data);

        return $this->ok($this->vehicleData($model->load('logs'), true));
    }

    public function destroy(int $vehicle): JsonResponse
    {
        $this->find($vehicle)->delete();

        return $this->ok();
    }

    /**
     * A log entry: refuelling/charging, a cost (service, repair, tyres …) or a trip for the logbook.
     */
    public function storeLog(Request $request, int $vehicle): JsonResponse
    {
        $model = $this->find($vehicle);
        $isTrip = $request->input('kind') === 'trip';
        $data = $request->validate([
            'kind' => ['required', Rule::in(VehicleLog::KINDS)],
            'date' => ['required', 'date_format:Y-m-d'],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'distance_km' => [$isTrip ? 'required' : 'nullable', 'integer', 'min:1', 'max:20000'],
            'purpose' => [$isTrip ? 'required' : 'nullable', Rule::in(VehicleLog::PURPOSES)],
            'destination' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $model->logs()->create([
            'created_by' => $this->user()->id,
            'kind' => $data['kind'],
            'date' => $data['date'],
            'odometer' => $data['odometer'] ?? null,
            'amount' => $isTrip ? null : ($data['amount'] ?? null),
            'quantity' => $data['kind'] === 'fuel' ? ($data['quantity'] ?? null) : null,
            'distance_km' => $isTrip ? (int) $data['distance_km'] : null,
            'purpose' => $isTrip ? $data['purpose'] : null,
            'destination' => trim((string) ($data['destination'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ]);

        return $this->ok($this->vehicleData($model->load('logs'), true), 201);
    }

    public function destroyLog(int $vehicle, int $log): JsonResponse
    {
        $model = $this->find($vehicle);
        $model->logs()->findOrFail($log)->delete();

        return $this->ok($this->vehicleData($model->load('logs'), true));
    }

    private function find(int $id): Vehicle
    {
        return Vehicle::query()->visibleTo($this->user(), $this->household()->id)->with('logs')->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function day(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicleData(Vehicle $vehicle, bool $withLogs = false): array
    {
        $stats = VehicleStats::for($vehicle);
        $electric = $vehicle->isElectric();

        $data = [
            'id' => $vehicle->id,
            'name' => $vehicle->name,
            'make_model' => $vehicle->make_model,
            'plate' => $vehicle->plate,
            'fuel' => $vehicle->fuel,
            'electric' => $electric,
            'inspection_due' => $this->day($vehicle->inspection_due),
            'service_due' => $this->day($vehicle->service_due),
            'service_due_km' => $vehicle->service_due_km,
            'tax_yearly' => $vehicle->tax_yearly,
            'insurance_yearly' => $vehicle->insurance_yearly,
            'notes' => $vehicle->notes,
            'private' => $vehicle->visibility === Item::VISIBILITY_PRIVATE,
            'odometer' => $stats['odometer'],
            'km_12m' => $stats['km_12m'],
            'costs_12m' => round($stats['costs_12m'], 2),
            'monthly' => round($stats['monthly'], 2),
            'per_km' => $stats['per_km'],
            'consumption' => $stats['consumption'],
            'consumption_unit' => $stats['unit'],
            'next' => $stats['next'],
        ];

        if ($withLogs) {
            $data['kinds'] = collect(VehicleLog::kindLabels($electric))->map(fn (array $kind, string $key) => ['key' => $key] + $kind)->values()->all();
            $data['purposes'] = collect(VehicleLog::purposeLabels())->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all();
            $data['logs'] = VehicleLog::where('vehicle_id', $vehicle->id)->orderByDesc('date')->orderByDesc('id')->limit(200)->get()->map(fn (VehicleLog $log) => [
                'id' => $log->id,
                'kind' => $log->kind,
                'date' => $this->day($log->date),
                'odometer' => $log->odometer,
                'amount' => $log->amount !== null ? (float) $log->amount : null,
                'quantity' => $log->quantity !== null ? (float) $log->quantity : null,
                'distance_km' => $log->distance_km,
                'purpose' => $log->purpose,
                'destination' => $log->destination,
                'notes' => $log->notes,
            ])->values()->all();
        }

        return $data;
    }
}
