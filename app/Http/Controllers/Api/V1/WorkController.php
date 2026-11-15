<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WorkDay;
use App\Models\WorkProfile;
use App\Support\Life\PublicHolidays;
use App\Support\Life\WorkTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Working time account and leave planning for the app: target/actual per day, overtime balance,
 * leave entitlement, public holidays of the state and bridge day tips. Personal data of the member.
 */
class WorkController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $profile = WorkProfile::for($this->user());
        $time = WorkTime::forProfile($profile);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : today()->startOfWeek(Carbon::MONDAY);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->startOfDay() : $from->copy()->addDays(6);
        abort_if($from->diffInDays($to) > 62, 422, __('The period is too long.'));
        $year = today()->year;
        $yearDays = WorkDay::where('user_id', $this->user()->id)->whereYear('date', $year)->get();
        $rangeDays = WorkDay::where('user_id', $this->user()->id)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get();
        $period = $time->period($from, $to, $rangeDays);
        $today = WorkDay::where('user_id', $this->user()->id)->whereDate('date', today())->first();

        return $this->ok([
            'settings' => $this->settings($profile),
            'states' => PublicHolidays::states(),
            'types' => collect(WorkDay::types())->map(fn (array $t, string $key) => ['key' => $key, 'label' => $t['label'], 'icon' => $t['icon']])->values()->all(),
            'today' => $today ? $this->dayData($today) + ['minutes' => $today->workedMinutes()] : null,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'target' => $period['target'],
                'actual' => $period['actual'],
                'diff' => $period['diff'],
                'rows' => collect($period['rows'])->map(fn (array $row) => [
                    'date' => $row['date']->toDateString(),
                    'holiday' => $row['holiday'],
                    'working' => $row['working'],
                    'day' => $row['day'] ? $this->dayData($row['day']) : null,
                    'target' => $row['target'],
                    'actual' => $row['actual'],
                    'diff' => $row['diff'],
                    'hint' => $row['hint'],
                    'missing' => $row['missing'],
                ])->all(),
            ],
            'balance' => $time->balance($yearDays),
            'leave' => $time->leave($year, $yearDays),
            'upcoming_holidays' => collect($time->holidays($year) + $time->holidays($year + 1))->filter(fn ($name, $date) => $date >= today()->toDateString())->take(6)->map(fn ($name, $date) => ['date' => $date, 'name' => $name])->values()->all(),
            'bridge_days' => collect($time->bridgeDays($year))->map(fn (array $tip) => [
                'holiday' => $tip['holiday'],
                'date' => $tip['date']->toDateString(),
                'leave' => array_map(fn (Carbon $d) => $d->toDateString(), $tip['leave']),
                'free_days' => $tip['free_days'],
            ])->all(),
        ]);
    }

    public function clock(): JsonResponse
    {
        $entry = $this->entry(today()->toDateString());

        if (! $entry->exists || ! $entry->starts_at) {
            $entry->fill(['type' => 'work', 'starts_at' => now()->format('H:i'), 'ends_at' => null])->save();
        } else {
            $entry->ends_at = now()->format('H:i');
            $worked = $entry->workedMinutes();
            $entry->break_minutes = max($entry->break_minutes, $worked > 555 ? 45 : ($worked > 390 ? 30 : 0));
            $entry->save();
        }

        return $this->ok($this->dayData($entry));
    }

    public function saveDay(Request $request, string $date): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(WorkDay::TYPES)],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $withTimes = in_array($data['type'], ['work', 'half_vacation'], true);
        $entry = $this->entry($date);
        $entry->fill([
            'type' => $data['type'],
            'starts_at' => $withTimes ? (($data['starts_at'] ?? null) ?: null) : null,
            'ends_at' => $withTimes ? (($data['ends_at'] ?? null) ?: null) : null,
            'break_minutes' => $withTimes ? (int) ($data['break_minutes'] ?? 0) : 0,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ])->save();

        return $this->ok($this->dayData($entry));
    }

    public function clearDay(string $date): JsonResponse
    {
        WorkDay::where('user_id', $this->user()->id)->whereDate('date', Carbon::parse($date)->toDateString())->delete();

        return $this->ok();
    }

    public function planLeave(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'half' => ['sometimes', 'boolean']]);
        $time = WorkTime::forProfile(WorkProfile::for($this->user()));
        $dates = $time->workingDaysBetween(Carbon::parse($data['from']), Carbon::parse($data['to']));

        if ($dates === []) {
            return response()->json(['success' => false, 'message' => __('There is no working day in this period.')], 422);
        }

        abort_if(count($dates) > 120, 422, __('The period is too long.'));

        foreach ($dates as $date) {
            $entry = $this->entry($date->toDateString());

            if (! $entry->exists || $entry->type !== 'work' || ! $entry->starts_at) {
                $entry->fill(['type' => $request->boolean('half') ? 'half_vacation' : 'vacation', 'starts_at' => null, 'ends_at' => null, 'break_minutes' => 0])->save();
            }
        }

        return $this->ok(['days' => count($dates) * ($request->boolean('half') ? 0.5 : 1)], 201);
    }

    public function cancelLeave(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        WorkDay::where('user_id', $this->user()->id)->whereIn('type', ['vacation', 'half_vacation'])
            ->whereDate('date', '>=', Carbon::parse($data['from'])->toDateString())->whereDate('date', '<=', Carbon::parse($data['to'])->toDateString())->delete();

        return $this->ok();
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'weekly_hours' => ['required', 'numeric', 'min:0', 'max:80'],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7'],
            'vacation_days' => ['required', 'numeric', 'min:0', 'max:100'],
            'carry_over_days' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'state' => ['required', Rule::in(array_keys(PublicHolidays::states()))],
            'overtime_start_minutes' => ['nullable', 'integer', 'between:-100000,100000'],
        ]);
        $profile = WorkProfile::for($this->user());
        $profile->update([
            'weekly_hours' => (float) $data['weekly_hours'],
            'work_days' => array_values(array_unique(array_map('intval', $data['work_days']))),
            'vacation_days' => (float) $data['vacation_days'],
            'carry_over_days' => (float) ($data['carry_over_days'] ?? 0),
            'carry_over_year' => today()->year,
            'state' => $data['state'],
            'overtime_start_minutes' => (int) ($data['overtime_start_minutes'] ?? 0),
        ]);

        return $this->ok($this->settings($profile->refresh()));
    }

    private function entry(string $date): WorkDay
    {
        $date = Carbon::parse($date)->toDateString();

        return WorkDay::where('user_id', $this->user()->id)->whereDate('date', $date)->first() ?? new WorkDay(['user_id' => $this->user()->id, 'date' => $date]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dayData(WorkDay $day): array
    {
        return [
            'date' => $day->date->toDateString(),
            'type' => $day->type,
            'starts_at' => $day->starts_at ? substr((string) $day->starts_at, 0, 5) : null,
            'ends_at' => $day->ends_at ? substr((string) $day->ends_at, 0, 5) : null,
            'break_minutes' => (int) $day->break_minutes,
            'note' => $day->note,
            'worked' => $day->workedMinutes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(WorkProfile $profile): array
    {
        return [
            'weekly_hours' => $profile->weekly_hours,
            'work_days' => array_map('intval', $profile->work_days),
            'vacation_days' => $profile->vacation_days,
            'carry_over_days' => $profile->carry_over_year === today()->year ? $profile->carry_over_days : 0.0,
            'state' => $profile->state,
            'overtime_start_minutes' => $profile->overtime_start_minutes,
        ];
    }
}
