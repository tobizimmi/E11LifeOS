<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\HomeTask;
use App\Models\Household;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Support\Life\HomeMaintenance;
use App\Support\Life\MeterStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * "At home" for the app: recurring maintenance (done → next date) and the meters with their
 * readings and consumption.
 */
class HouseController extends ApiController
{
    public function index(): JsonResponse
    {
        $householdId = $this->household()->id;
        $existing = HomeTask::where('household_id', $householdId)->pluck('key')->filter()->all();

        return $this->ok([
            'tasks' => HomeTask::where('household_id', $householdId)->orderBy('next_due_on')->get()->map(fn (HomeTask $task) => $this->taskData($task))->all(),
            'library' => collect(HomeMaintenance::library())->reject(fn (array $preset, string $key) => in_array($key, $existing, true))
                ->map(fn (array $preset, string $key) => ['key' => $key, 'title' => $preset['title'], 'icon' => $preset['icon'], 'interval_months' => $preset['interval'], 'hint' => $preset['hint']])->values()->all(),
            'meter_kinds' => collect(Meter::kindLabels())->map(fn (array $kind, string $key) => ['key' => $key] + $kind)->values()->all(),
            'meters' => Meter::where('household_id', $householdId)->with('readings')->orderBy('name')->get()->map(fn (Meter $meter) => $this->meterData($meter))->all(),
        ]);
    }

    /**
     * Adds maintenance: presets from the library (keys) or an own task (title, interval, next date).
     */
    public function storeTask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keys' => ['required_without:title', 'array', 'max:50'],
            'keys.*' => ['string', 'max:64'],
            'title' => ['required_without:keys', 'string', 'max:255'],
            'interval_months' => ['required_with:title', 'integer', 'between:1,120'],
            'next_due_on' => ['required_with:title', 'date_format:Y-m-d'],
        ]);

        $household = $this->household();

        if (isset($data['title'])) {
            HomeTask::create(['household_id' => $household->id, 'created_by' => $this->user()->id, 'title' => trim($data['title']), 'icon' => '🔧', 'interval_months' => (int) $data['interval_months'], 'next_due_on' => $data['next_due_on']]);
        }

        $library = HomeMaintenance::library();
        $existing = HomeTask::where('household_id', $household->id)->pluck('key')->all();

        foreach (array_unique($data['keys'] ?? []) as $key) {
            if (! isset($library[$key]) || in_array($key, $existing, true)) {
                continue;
            }

            HomeTask::create([
                'household_id' => $household->id,
                'created_by' => $this->user()->id,
                'key' => $key,
                'title' => $library[$key]['title'],
                'icon' => $library[$key]['icon'],
                'interval_months' => $library[$key]['interval'],
                'next_due_on' => HomeMaintenance::firstDueOn($library[$key]),
                'notes' => $library[$key]['hint'] ?: null,
            ]);
        }

        return $this->index();
    }

    /**
     * Done today: the next date moves on by the interval.
     */
    public function taskDone(int $task): JsonResponse
    {
        $model = $this->findTask($task);
        $model->markDone();

        return $this->ok($this->taskData($model->refresh()));
    }

    public function destroyTask(int $task): JsonResponse
    {
        $this->findTask($task)->delete();

        return $this->ok();
    }

    public function storeMeter(Request $request): JsonResponse
    {
        $meter = Meter::create($this->meterAttributes($request) + ['household_id' => $this->household()->id, 'created_by' => $this->user()->id]);

        return $this->ok($this->meterData($meter->load('readings')), 201);
    }

    public function updateMeter(Request $request, int $meter): JsonResponse
    {
        $model = $this->findMeter($meter);
        $model->update($this->meterAttributes($request));

        return $this->ok($this->meterData($model->load('readings')));
    }

    public function destroyMeter(int $meter): JsonResponse
    {
        $this->findMeter($meter)->delete();

        return $this->ok();
    }

    public function storeReading(Request $request, int $meter): JsonResponse
    {
        $data = $request->validate([
            'read_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:0', 'max:99999999999'],
        ]);
        $model = $this->findMeter($meter);
        $model->readings()->create(['created_by' => $this->user()->id, 'read_on' => $data['read_on'] ?? today()->toDateString(), 'value' => $data['value']]);

        return $this->ok($this->meterData($model->load('readings')), 201);
    }

    public function destroyReading(int $meter, int $reading): JsonResponse
    {
        $model = $this->findMeter($meter);
        $model->readings()->findOrFail($reading)->delete();

        return $this->ok($this->meterData($model->load('readings')));
    }

    /**
     * @return array<string, mixed>
     */
    private function meterAttributes(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(Meter::KINDS)],
            'number' => ['nullable', 'string', 'max:64'],
            'price_per_unit' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'base_fee_monthly' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'monthly_advance' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        return [
            'name' => trim($data['name']),
            'kind' => $data['kind'],
            'number' => trim((string) ($data['number'] ?? '')) ?: null,
            'price_per_unit' => $data['price_per_unit'] ?? null,
            'base_fee_monthly' => $data['base_fee_monthly'] ?? null,
            'monthly_advance' => $data['monthly_advance'] ?? null,
        ];
    }

    private function findTask(int $id): HomeTask
    {
        return HomeTask::where('household_id', $this->household()->id)->find($id) ?? abort(404);
    }

    private function findMeter(int $id): Meter
    {
        return Meter::where('household_id', $this->household()->id)->find($id) ?? abort(404);
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
    private function taskData(HomeTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'icon' => $task->icon,
            'interval_months' => (int) $task->interval_months,
            'last_done_on' => $this->day($task->last_done_on),
            'next_due_on' => $this->day($task->next_due_on),
            'overdue' => $task->isOverdue(),
            'due_soon' => $task->isDueSoon(),
            'notes' => $task->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meterData(Meter $meter): array
    {
        $stats = MeterStats::for($meter);

        return [
            'id' => $meter->id,
            'name' => $meter->name,
            'kind' => $meter->kind,
            'unit' => $meter->unit(),
            'number' => $meter->number,
            'price_per_unit' => $meter->price_per_unit,
            'base_fee_monthly' => $meter->base_fee_monthly,
            'monthly_advance' => $meter->monthly_advance,
            'latest' => $stats['latest'],
            'yearly' => $stats['yearly'],
            'yearly_cost' => $stats['yearly_cost'],
            'balance' => $stats['balance'],
            'suggested_advance' => $stats['suggested_advance'],
            'periods' => array_slice($stats['periods'], 0, 12),
            'readings' => $meter->readings->sortByDesc(fn (MeterReading $reading) => $this->day($reading->read_on))->take(24)
                ->map(fn (MeterReading $reading) => ['id' => $reading->id, 'read_on' => $this->day($reading->read_on), 'value' => (float) $reading->value])->values()->all(),
        ];
    }
}
