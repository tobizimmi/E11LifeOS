<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CareerEntry;
use App\Models\Household;
use App\Support\Career\CareerPlanner;
use App\Support\PdfTheme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Career for the app: timeline of jobs, education, trainings and milestones, work contracts,
 * applications, the profile and the CV as PDF. Personal – only the member sees it.
 */
class CareerController extends ApiController
{
    public function index(): JsonResponse
    {
        $entries = $this->entries()->get();
        $terms = CareerPlanner::searchTerms($this->user(), $entries);

        return $this->ok([
            'entries' => $entries->sortByDesc(fn (CareerEntry $entry) => ($entry->starts_on ?? $entry->ends_on)?->toDateString() ?? '')->map(fn (CareerEntry $entry) => $this->entryData($entry))->values()->all(),
            'hints' => CareerPlanner::hints($entries),
            'profile' => CareerPlanner::profile($this->user()),
            'types' => collect(CareerEntry::types())->map(fn (array $t, string $key) => ['key' => $key, 'label' => $t['label'], 'icon' => $t['icon']])->values()->all(),
            'statuses' => collect(CareerEntry::applicationStatuses())->map(fn (array $s, string $key) => ['key' => $key, 'label' => $s['label']])->values()->all(),
            'search_links' => CareerPlanner::searchLinks($terms['what'], $terms['where']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entry = CareerEntry::create($this->attributes($request) + ['user_id' => $this->user()->id, 'household_id' => $this->household()->id]);

        return $this->ok($this->entryData($entry), 201);
    }

    public function update(Request $request, int $entry): JsonResponse
    {
        $model = $this->entries()->findOrFail($entry);
        $model->update($this->attributes($request));

        return $this->ok($this->entryData($model->refresh()));
    }

    public function destroy(int $entry): JsonResponse
    {
        $this->entries()->findOrFail($entry)->delete();

        return $this->ok();
    }

    public function status(Request $request, int $entry): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(CareerEntry::APPLICATION_STATUSES)]]);
        $model = $this->entries()->findOrFail($entry);
        abort_unless($model->type === 'application', 422);
        $model->update(['details' => array_merge((array) $model->details, ['status' => $data['status']])]);

        return $this->ok($this->entryData($model->refresh()));
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'headline' => ['nullable', 'string', 'max:150'],
            'summary' => ['nullable', 'string', 'max:3000'],
            'skills' => ['nullable', 'string', 'max:2000'],
            'languages' => ['nullable', 'string', 'max:500'],
            'contact' => ['nullable', 'string', 'max:500'],
            'desired_role' => ['nullable', 'string', 'max:150'],
            'desired_location' => ['nullable', 'string', 'max:150'],
        ]);
        $keys = ['headline', 'summary', 'skills', 'languages', 'contact', 'desired_role', 'desired_location'];
        $this->user()->update(['career_profile' => array_combine($keys, array_map(fn (string $key) => trim((string) ($data[$key] ?? '')), $keys))]);

        return $this->ok(CareerPlanner::profile($this->user()->refresh()));
    }

    /**
     * Current job offers from the job exchange for the wished-for role (or the latest job).
     */
    public function jobs(): JsonResponse
    {
        $terms = CareerPlanner::searchTerms($this->user(), $this->entries()->get());

        return $this->ok(['what' => $terms['what'], 'where' => $terms['where'], 'jobs' => CareerPlanner::jobSuggestions($terms['what'], $terms['where'])]);
    }

    /**
     * Remembers a suggested job as a planned application.
     */
    public function rememberJob(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'employer' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'url' => ['required', 'url:https', 'max:1000'],
        ]);

        $entry = $this->entries()->where('type', 'application')->get()->first(fn (CareerEntry $e) => $e->detail('url') === $data['url']);
        $entry ??= CareerEntry::create([
            'user_id' => $this->user()->id, 'household_id' => $this->household()->id, 'type' => 'application', 'title' => trim($data['title']),
            'organization' => trim((string) ($data['employer'] ?? '')) ?: null, 'location' => trim((string) ($data['location'] ?? '')) ?: null,
            'details' => ['status' => 'planned', 'url' => $data['url']],
        ]);

        return $this->ok($this->entryData($entry), 201);
    }

    public function cv(): Response
    {
        $user = $this->user();
        $householdId = $this->household()->id;
        $timeline = CareerPlanner::timeline($this->entries()->get());

        $html = view('career.cv', [
            'user' => $user,
            'profile' => CareerPlanner::profile($user),
            'skills' => CareerPlanner::skills($user),
            'sections' => [
                __('Work experience') => $timeline->where('type', 'job'),
                __('Education') => $timeline->where('type', 'education'),
                __('Further training') => $timeline->where('type', 'training'),
            ],
        ])->render();

        return response(PdfTheme::render($html, $householdId, footer: false), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.(Str::slug(__('CV').' '.$user->name, '-', 'de') ?: 'lebenslauf').'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return Builder<CareerEntry>
     */
    private function entries(): Builder
    {
        return CareerEntry::where('user_id', $this->user()->id)->where('household_id', $this->household()->id);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(CareerEntry::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'description' => ['nullable', 'string', 'max:5000'],
            'grade' => ['nullable', 'string', 'max:50'],
            'hours' => ['nullable', 'string', 'max:20'],
            'expires_on' => ['nullable', 'date'],
            'salary' => ['nullable', 'string', 'max:100'],
            'weekly_hours' => ['nullable', 'string', 'max:20'],
            'vacation_days' => ['nullable', 'string', 'max:20'],
            'notice_period' => ['nullable', 'string', 'max:100'],
            'probation_until' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(CareerEntry::APPLICATION_STATUSES)],
            'url' => ['nullable', 'url:http,https', 'max:1000'],
            'contact' => ['nullable', 'string', 'max:255'],
        ]);

        $detailKeys = match ($data['type']) {
            'education' => ['grade'],
            'training' => ['hours', 'expires_on'],
            'contract' => ['salary', 'weekly_hours', 'vacation_days', 'notice_period', 'probation_until'],
            'application' => ['status', 'url', 'contact'],
            default => [],
        };
        $details = array_filter(array_map(fn ($value) => trim((string) $value), array_intersect_key($data, array_flip($detailKeys))), fn (string $value) => $value !== '');

        return [
            'type' => $data['type'],
            'title' => trim($data['title']),
            'organization' => trim((string) ($data['organization'] ?? '')) ?: null,
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
            'starts_on' => ($data['starts_on'] ?? null) ?: null,
            'ends_on' => ($data['ends_on'] ?? null) ?: null,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'details' => $details ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(CareerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->type,
            'title' => $entry->title,
            'organization' => $entry->organization,
            'location' => $entry->location,
            'starts_on' => $entry->starts_on?->toDateString(),
            'ends_on' => $entry->ends_on?->toDateString(),
            'description' => $entry->description,
            'current' => $entry->isCurrent(),
            'details' => (object) ((array) $entry->details),
        ];
    }
}
