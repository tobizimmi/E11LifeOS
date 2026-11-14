<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Habit;
use App\Support\Life\HabitTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Habits for the app: the member's routines with today's amount, streak and the last week,
 * ticking off / counting, and managing them. Habits belong to the member alone.
 */
class HabitController extends ApiController
{
    /**
     * Active habits for a day (default: today), each with its amount, stats and the last 7 days.
     */
    public function index(Request $request): JsonResponse
    {
        $day = $this->day($request);
        $habits = HabitTracker::active($this->user());
        $logs = HabitTracker::logs($habits, today()->max($day));

        return $this->ok([
            'date' => $day->toDateString(),
            'habits' => $habits->map(fn (Habit $habit) => $this->habitData($habit, $logs[$habit->id] ?? [], $day))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user();
        $habit = Habit::create($this->attributes($request) + [
            'user_id' => $user->id,
            'position' => (int) Habit::where('user_id', $user->id)->max('position') + 1,
        ]);

        return $this->ok($this->habitData($habit->refresh(), [], today()), 201);
    }

    public function update(Request $request, int $habit): JsonResponse
    {
        $model = $this->find($habit);
        $model->update($this->attributes($request));

        return $this->ok($this->habitData($model, HabitTracker::logs(collect([$model]))[$model->id] ?? [], today()));
    }

    /**
     * Ticks a habit off (or back), or adds/removes one of a counted habit, for a day.
     */
    public function tap(Request $request, int $habit): JsonResponse
    {
        $data = $request->validate(['step' => ['sometimes', 'integer', 'in:-1,1']]);
        $day = $this->day($request);
        $model = $this->find($habit);
        HabitTracker::tap($model, $day, (int) ($data['step'] ?? 1));

        return $this->ok($this->habitData($model, HabitTracker::logs(collect([$model]), today()->max($day))[$model->id] ?? [], $day));
    }

    /**
     * Archives a habit (it keeps its history), or brings it back.
     */
    public function archive(int $habit): JsonResponse
    {
        $model = $this->find($habit);
        $model->update(['archived_at' => $model->archived_at ? null : now()]);

        return $this->ok(['id' => $model->id, 'archived' => $model->archived_at !== null]);
    }

    public function destroy(int $habit): JsonResponse
    {
        $this->find($habit)->delete();

        return $this->ok();
    }

    private function day(Request $request): Carbon
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:tomorrow']]);

        return Carbon::parse($data['date'] ?? today()->toDateString())->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:16'],
            'type' => ['required', 'in:check,count'],
            'target' => ['required_if:type,count', 'nullable', 'integer', 'min:1', 'max:9999'],
            'unit' => ['nullable', 'string', 'max:30'],
            'schedule' => ['required', 'in:daily,weekdays,weekly'],
            'days' => ['required_if:schedule,weekdays', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'per_week' => ['required_if:schedule,weekly', 'nullable', 'integer', 'min:1', 'max:7'],
            'reminder_at' => ['nullable', 'date_format:H:i'],
        ]);

        return [
            'name' => trim($data['name']),
            'icon' => trim((string) ($data['icon'] ?? '')) ?: '✅',
            'type' => $data['type'],
            'target' => $data['type'] === Habit::COUNT ? (int) $data['target'] : 1,
            'unit' => $data['type'] === Habit::COUNT ? (trim((string) ($data['unit'] ?? '')) ?: null) : null,
            'schedule' => $data['schedule'],
            'days' => $data['schedule'] === Habit::WEEKDAYS ? array_values(array_map('intval', $data['days'])) : null,
            'per_week' => $data['schedule'] === Habit::WEEKLY ? (int) $data['per_week'] : null,
            'reminder_at' => ($data['reminder_at'] ?? null) ?: null,
        ];
    }

    private function find(int $id): Habit
    {
        return Habit::where('user_id', $this->user()->id)->find($id) ?? abort(404);
    }

    /**
     * @param  array<string, int>  $logs  Y-m-d => value
     * @return array<string, mixed>
     */
    private function habitData(Habit $habit, array $logs, Carbon $day): array
    {
        $stats = HabitTracker::stats($habit, $logs);
        $value = $logs[$day->toDateString()] ?? 0;

        return [
            'id' => $habit->id,
            'name' => $habit->name,
            'icon' => $habit->icon,
            'type' => $habit->type,
            'target' => $habit->type === Habit::COUNT ? max(1, $habit->target) : 1,
            'unit' => $habit->unit,
            'schedule' => $habit->schedule,
            'days' => $habit->days,
            'per_week' => $habit->per_week,
            'reminder_at' => $habit->reminder_at ? substr($habit->reminder_at, 0, 5) : null,
            'value' => $value,
            'done' => $habit->isFulfilledBy($value),
            'due' => $habit->isDueOn($day),
            'streak' => $stats['streak'],
            'best' => $stats['best'],
            'rate' => $stats['rate'],
            'week' => collect(range(6, 0))->map(function (int $back) use ($day, $logs, $habit) {
                $date = $day->copy()->subDays($back);
                $amount = $logs[$date->toDateString()] ?? 0;

                return ['date' => $date->toDateString(), 'value' => $amount, 'done' => $habit->isFulfilledBy($amount), 'due' => $habit->isDueOn($date)];
            })->all(),
        ];
    }
}
