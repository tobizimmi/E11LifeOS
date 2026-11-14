<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\HealthEntry;
use App\Models\HealthProfile;
use App\Support\Life\HealthPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Health for the app (behind the password confirmation): personal profiles with emergency card
 * data, prevention, medication and appointments. Health data belongs to the member alone.
 */
class HealthController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok([
            'blood_types' => HealthProfile::BLOOD_TYPES,
            'profiles' => HealthProfile::where('user_id', $this->user()->id)->orderBy('id')->get()->map(fn (HealthProfile $profile) => $this->profileData($profile))->all(),
        ]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $profile = HealthProfile::create($this->profileAttributes($request) + ['user_id' => $this->user()->id]);

        return $this->ok($this->profileData($profile), 201);
    }

    public function updateProfile(Request $request, int $profile): JsonResponse
    {
        $model = $this->findProfile($profile);
        $model->update($this->profileAttributes($request));

        return $this->ok($this->profileData($model));
    }

    public function destroyProfile(int $profile): JsonResponse
    {
        $this->findProfile($profile)->delete();

        return $this->ok();
    }

    public function storeEntry(Request $request, int $profile): JsonResponse
    {
        $model = $this->findProfile($profile);
        $kind = (string) $request->input('kind');
        $data = $request->validate([
            'kind' => ['required', Rule::in([HealthEntry::CHECKUP, HealthEntry::VACCINATION, HealthEntry::APPOINTMENT, HealthEntry::MEDICATION])],
            'title' => ['required', 'string', 'max:255'],
            'done_on' => ['nullable', 'date_format:Y-m-d'],
            'next_due_on' => [$kind === HealthEntry::APPOINTMENT ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'interval_months' => ['nullable', 'integer', 'between:1,240'],
            'time_of_day' => ['nullable', 'date_format:H:i'],
            'times' => ['nullable', 'string', 'max:100', 'regex:/^\s*([01]\d|2[0-3]):[0-5]\d(\s*,\s*([01]\d|2[0-3]):[0-5]\d)*\s*$/'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $times = ($data['times'] ?? null) ? array_values(array_unique(array_map('trim', explode(',', $data['times'])))) : null;
        $next = ($data['next_due_on'] ?? null) ?: (($data['done_on'] ?? null) && ($data['interval_months'] ?? null)
            ? Carbon::parse($data['done_on'])->addMonthsNoOverflow((int) $data['interval_months'])->toDateString() : null);

        $model->entries()->create([
            'kind' => $data['kind'],
            'title' => trim($data['title']),
            'done_on' => ($data['done_on'] ?? null) ?: null,
            'next_due_on' => $next,
            'interval_months' => ($data['interval_months'] ?? null) ?: null,
            'time_of_day' => ($data['time_of_day'] ?? null) ?: null,
            'times' => $data['kind'] === HealthEntry::MEDICATION ? $times : null,
            'dosage' => trim((string) ($data['dosage'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ]);

        return $this->ok($this->profileData($model->refresh()), 201);
    }

    /**
     * Takes over a suggestion of the prevention plan (by its key).
     */
    public function adopt(Request $request, int $profile): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:100']]);
        $model = $this->findProfile($profile);
        $suggestion = collect(HealthPlan::suggestions($model))->firstWhere('key', $data['key']) ?? abort(404);

        $model->entries()->create(['kind' => $suggestion['kind'], 'key' => $data['key'], 'title' => $suggestion['title'], 'next_due_on' => $suggestion['due_on'], 'interval_months' => $suggestion['interval'], 'notes' => $suggestion['hint'] ?: null]);

        return $this->ok($this->profileData($model->refresh()), 201);
    }

    /**
     * Done today: the next date moves on by the interval.
     */
    public function done(int $profile, int $entry): JsonResponse
    {
        $model = $this->findProfile($profile);
        $this->findEntry($model, $entry)->markDone();

        return $this->ok($this->profileData($model->refresh()));
    }

    public function toggleActive(int $profile, int $entry): JsonResponse
    {
        $model = $this->findProfile($profile);
        $item = $this->findEntry($model, $entry);
        $item->update(['active' => ! $item->active]);

        return $this->ok($this->profileData($model->refresh()));
    }

    public function destroyEntry(int $profile, int $entry): JsonResponse
    {
        $model = $this->findProfile($profile);
        $this->findEntry($model, $entry)->delete();

        return $this->ok($this->profileData($model->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function profileAttributes(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['nullable', Rule::in(['f', 'm', 'd'])],
            'blood_type' => ['nullable', Rule::in(HealthProfile::BLOOD_TYPES)],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'insurance' => ['nullable', 'string', 'max:500'],
            'emergency_contact' => ['nullable', 'string', 'max:500'],
            'doctors' => ['nullable', 'string', 'max:2000'],
        ]);

        return collect($data)->map(fn (mixed $value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value))->all();
    }

    private function findEntry(HealthProfile $profile, int $id): HealthEntry
    {
        return HealthEntry::where('health_profile_id', $profile->id)->findOrFail($id);
    }

    private function findProfile(int $id): HealthProfile
    {
        return HealthProfile::where('user_id', $this->user()->id)->find($id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileData(HealthProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'birth_date' => $this->day($profile->birth_date),
            'age' => $profile->age(),
            'sex' => $profile->sex,
            'blood_type' => $profile->blood_type,
            'allergies' => $profile->allergies,
            'conditions' => $profile->conditions,
            'insurance' => $profile->insurance,
            'emergency_contact' => $profile->emergency_contact,
            'doctors' => $profile->doctors,
            'entries' => $profile->entries()->get()->map(fn ($entry) => [
                'id' => $entry->getKey(),
                'kind' => $entry->getAttribute('kind'),
                'title' => $entry->getAttribute('title'),
                'done_on' => $this->day($entry->getAttribute('done_on')),
                'next_due_on' => $this->day($entry->getAttribute('next_due_on')),
                'interval_months' => $entry->getAttribute('interval_months'),
                'time_of_day' => $entry->getAttribute('time_of_day'),
                'times' => $entry->getAttribute('times'),
                'dosage' => $entry->getAttribute('dosage'),
                'active' => (bool) $entry->getAttribute('active'),
                'notes' => $entry->getAttribute('notes'),
                'overdue' => $entry instanceof HealthEntry && $entry->isOverdue(),
            ])->all(),
            'suggestions' => HealthPlan::suggestions($profile),
        ];
    }

    private function day(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }
}
