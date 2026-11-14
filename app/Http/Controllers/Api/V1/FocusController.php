<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Collection;
use App\Models\FocusEntry;
use App\Models\Household;
use App\Models\Item;
use App\Support\QuickCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Focus & time boxing for the app: brain dump, Eisenhower quadrants, top-3 of the day and time
 * boxes. Entries are personal; ticking one off syncs the linked to-do.
 */
class FocusController extends ApiController
{
    public const DURATIONS = [15, 30, 45, 60, 90, 120];

    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->dayData($this->day($request)));
    }

    /**
     * Brain dump: a thought for the day, by default also saved as a linked to-do.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:500'], 'also_task' => ['sometimes', 'boolean']]);
        $day = $this->day($request);
        $title = trim($data['title']);
        $itemId = null;

        if ($data['also_task'] ?? true) {
            $task = QuickCapture::task($this->user(), $this->household()->id, $title, $day->toDateString());
            $itemId = $task->id;
            $title = $task->title;
        }

        FocusEntry::create([
            'household_id' => $this->household()->id,
            'user_id' => $this->user()->id,
            'item_id' => $itemId,
            'date' => $day->toDateString(),
            'title' => mb_substr($title, 0, 255),
            'position' => $this->nextPosition($day),
        ]);

        return $this->ok($this->dayData($day), 201);
    }

    /**
     * Pulls the member's own open to-dos into the day (already linked ones are skipped).
     */
    public function importTasks(Request $request): JsonResponse
    {
        $day = $this->day($request);
        $householdId = $this->household()->id;
        $linked = $this->entries($day)->pluck('item_id')->filter()->all();
        $position = $this->nextPosition($day);

        Item::query()
            ->where('collection_id', Collection::tasksFor($householdId)->id)
            ->where('created_by', $this->user()->id)
            ->where('done', false)
            ->whereNotIn('id', $linked)
            ->orderBy('position')
            ->limit(100)
            ->get()
            ->each(function (Item $task) use ($householdId, $day, &$position) {
                FocusEntry::create(['household_id' => $householdId, 'user_id' => $this->user()->id, 'item_id' => $task->id, 'date' => $day->toDateString(), 'title' => mb_substr($task->title, 0, 255), 'position' => $position++]);
            });

        return $this->ok($this->dayData($day));
    }

    /**
     * Changes an entry: quadrant (or null to unsort), top-3 star, time box, done.
     */
    public function update(Request $request, int $entry): JsonResponse
    {
        $data = $request->validate([
            'quadrant' => ['sometimes', 'nullable', Rule::in(FocusEntry::QUADRANTS)],
            'is_mit' => ['sometimes', 'boolean'],
            'start_minute' => ['sometimes', 'nullable', 'integer', 'between:0,1439'],
            'duration_minutes' => ['sometimes', 'integer', Rule::in(self::DURATIONS)],
            'done' => ['sometimes', 'boolean'],
        ]);

        $model = $this->find($entry);
        $day = $model->date;
        $changes = $data;

        if (array_key_exists('quadrant', $data)) {
            // Only the two "important" quadrants stay eligible for a top-3 star.
            $changes['is_mit'] = ($data['quadrant'] !== null) && ($changes['is_mit'] ?? $model->is_mit) && in_array($data['quadrant'], ['q1', 'q2'], true);
        }

        if (($changes['is_mit'] ?? false) && ! $model->is_mit && $this->entries($day)->where('is_mit', true)->count() >= FocusEntry::MAX_MITS) {
            abort(422, __('Pick at most :count most important tasks for the day.', ['count' => FocusEntry::MAX_MITS]));
        }

        if (($changes['is_mit'] ?? false) && ! array_key_exists('quadrant', $data) && $model->quadrant === null) {
            $changes['quadrant'] = 'q1';
        }

        $model->update($changes);

        if (array_key_exists('done', $data)) {
            $model->item?->update(['done' => $model->done]);
        }

        return $this->ok($this->dayData($day));
    }

    public function destroy(int $entry): JsonResponse
    {
        $model = $this->find($entry);
        $day = $model->date;
        $model->delete(); // the linked to-do stays in the task list

        return $this->ok($this->dayData($day));
    }

    /**
     * Proposes the top tasks from the Eisenhower result: open "do first" entries, then "schedule".
     */
    public function suggestTop(Request $request): JsonResponse
    {
        $day = $this->day($request);
        $entries = $this->entries($day);
        $picked = $entries->whereIn('quadrant', ['q1', 'q2'])->where('done', false)
            ->sortBy(fn (FocusEntry $entry) => [$entry->quadrant === 'q1' ? 0 : 1, $entry->position])->take(FocusEntry::MAX_MITS);

        FocusEntry::whereIn('id', $entries->pluck('id'))->update(['is_mit' => false]);
        FocusEntry::whereIn('id', $picked->pluck('id'))->update(['is_mit' => true]);

        return $this->ok($this->dayData($day));
    }

    private function day(Request $request): Carbon
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return Carbon::parse($data['date'] ?? today()->toDateString())->startOfDay();
    }

    private function find(int $id): FocusEntry
    {
        return FocusEntry::where('household_id', $this->household()->id)->where('user_id', $this->user()->id)->with('item')->find($id) ?? abort(404);
    }

    private function nextPosition(Carbon $day): int
    {
        return (int) $this->entries($day)->max('position') + 1;
    }

    /**
     * @return \Illuminate\Support\Collection<int, FocusEntry>
     */
    private function entries(Carbon $day): \Illuminate\Support\Collection
    {
        return FocusEntry::query()->forDay($this->user(), $this->household()->id, $day->toDateString())->orderBy('position')->orderBy('id')->get();
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function dayData(Carbon $day): array
    {
        return [
            'date' => $day->toDateString(),
            'durations' => self::DURATIONS,
            'max_mits' => FocusEntry::MAX_MITS,
            'entries' => $this->entries($day)->map(fn (FocusEntry $entry) => [
                'id' => $entry->id,
                'title' => $entry->title,
                'quadrant' => $entry->quadrant,
                'is_mit' => (bool) $entry->is_mit,
                'start_minute' => $entry->start_minute,
                'duration_minutes' => (int) $entry->duration_minutes,
                'time_range' => $entry->timeRange(),
                'done' => (bool) $entry->done,
                'linked' => $entry->item_id !== null,
            ])->values()->all(),
        ];
    }
}
