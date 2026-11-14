<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EmergencyEntry;
use App\Support\Life\EmergencyFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The emergency folder for the app (behind the password confirmation): where documents, directives,
 * contracts and access are – with expiry reminders. Trusted people are managed on the web.
 */
class EmergencyController extends ApiController
{
    public function index(): JsonResponse
    {
        $entries = EmergencyEntry::where('user_id', $this->user()->id)->orderBy('position')->orderBy('id')->get();

        return $this->ok([
            'sections' => collect(EmergencyFolder::sections())->map(fn (array $section, string $key) => [
                'key' => $key,
                'label' => $section['label'],
                'icon' => $section['icon'],
                'hint' => $section['hint'],
                'presets' => collect($section['presets'])->map(fn (array $preset, string $presetKey) => ['key' => $presetKey] + $preset)->values()->all(),
            ])->values()->all(),
            'progress' => EmergencyFolder::progress($entries),
            'entries' => $entries->map(fn ($entry) => $this->entryData($entry))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $sections = EmergencyFolder::sections();
        $data = $request->validate([
            'section' => ['required', 'string'],
            'kind' => ['nullable', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:1000'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'remind_days' => ['nullable', 'integer', 'between:1,365'],
        ]);
        $kind = $data['kind'] ?? EmergencyEntry::CUSTOM;
        abort_unless(isset($sections[$data['section']]) && ($kind === EmergencyEntry::CUSTOM || isset($sections[$data['section']]['presets'][$kind])), 422, __('Unknown section.'));

        $entry = EmergencyEntry::create($this->attributes($data) + [
            'user_id' => $this->user()->id,
            'section' => $data['section'],
            'kind' => $kind,
            'position' => (int) EmergencyEntry::where('user_id', $this->user()->id)->max('position') + 1,
        ]);

        return $this->ok($this->entryData($entry), 201);
    }

    public function update(Request $request, int $entry): JsonResponse
    {
        $model = $this->find($entry);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:1000'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'remind_days' => ['nullable', 'integer', 'between:1,365'],
        ]);
        $model->update($this->attributes($data));

        return $this->ok($this->entryData($model));
    }

    public function destroy(int $entry): JsonResponse
    {
        $this->find($entry)->delete();

        return $this->ok();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'title' => trim($data['title']),
            'details' => trim((string) ($data['details'] ?? '')) ?: null,
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
            'expires_on' => ($data['expires_on'] ?? null) ?: null,
            'remind_days' => (int) ($data['remind_days'] ?? 60),
        ];
    }

    private function find(int $id): EmergencyEntry
    {
        return EmergencyEntry::where('user_id', $this->user()->id)->find($id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(mixed $entry): array
    {
        $expires = $entry->getAttribute('expires_on');

        return [
            'id' => $entry->getKey(),
            'section' => $entry->getAttribute('section'),
            'kind' => $entry->getAttribute('kind'),
            'title' => $entry->getAttribute('title'),
            'details' => $entry->getAttribute('details'),
            'location' => $entry->getAttribute('location'),
            'expires_on' => $expires ? Carbon::parse($expires)->toDateString() : null,
            'remind_days' => (int) $entry->getAttribute('remind_days'),
            'expired' => $entry instanceof EmergencyEntry && $entry->isExpired(),
            'expires_soon' => $entry instanceof EmergencyEntry && $entry->expiresSoon(),
        ];
    }
}
