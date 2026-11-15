<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Collection;
use App\Models\Household;
use App\Models\Item;
use App\Models\Space;
use App\Support\QuickCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile API for spaces: workspaces ("Work", "Home", "Club") that group pages and tasks.
 */
class SpacesController extends ApiController
{
    public function index(): JsonResponse
    {
        $household = $this->household();
        $spaces = Space::query()->visibleTo($this->user(), $household->id)->get();
        $tasksId = Collection::tasksFor($household->id)->id;
        $visible = fn () => Item::query()->forHousehold($household->id)->visibleTo($this->user())->whereIn('space_id', $spaces->pluck('id'));
        $pages = $visible()->where('type', Item::TYPE_NOTE)->selectRaw('space_id, count(*) as aggregate')->groupBy('space_id')->pluck('aggregate', 'space_id');
        $tasks = $visible()->where('collection_id', $tasksId)->where('done', false)->selectRaw('space_id, count(*) as aggregate')->groupBy('space_id')->pluck('aggregate', 'space_id');

        return $this->ok([
            'icons' => Space::ICONS,
            'spaces' => $spaces->map(fn (Space $space) => $this->data($space) + ['pages' => (int) ($pages[$space->id] ?? 0), 'tasks' => (int) ($tasks[$space->id] ?? 0)])->values()->all(),
        ]);
    }

    public function show(int $space): JsonResponse
    {
        $household = $this->household();
        $item = $this->find($household, $space);
        $tasksId = Collection::tasksFor($household->id)->id;
        $visible = fn () => Item::query()->forHousehold($household->id)->visibleTo($this->user())->where('space_id', $item->id);

        return $this->ok($this->data($item) + [
            'pages' => $visible()->where('type', Item::TYPE_NOTE)->orderBy('title')->get(['id', 'title', 'icon'])->map(fn (Item $page) => ['id' => $page->id, 'title' => $page->title, 'icon' => $page->icon])->all(),
            'tasks' => $visible()->where('collection_id', $tasksId)->where('done', false)->orderBy('position')->limit(200)->get(['id', 'title'])->map(fn (Item $task) => ['id' => $task->id, 'title' => $task->title])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $household = $this->household();
        $data = $this->validated($request, true);
        $space = Space::create([
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'name' => trim($data['name']),
            'icon' => $data['icon'] ?? Space::ICONS[0],
            'visibility' => ! empty($data['private']) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'position' => (int) Space::where('household_id', $household->id)->max('position') + 1,
        ]);

        return $this->ok($this->data($space), 201);
    }

    public function update(Request $request, int $space): JsonResponse
    {
        $item = $this->find($this->household(), $space);
        $data = $this->validated($request, false);
        $attributes = array_filter(['name' => isset($data['name']) ? trim($data['name']) : null, 'icon' => $data['icon'] ?? null], fn ($value) => $value !== null);

        // Only the creator can change the visibility (a shared space made private would vanish for the others).
        if (array_key_exists('private', $data) && $item->created_by === $this->user()->id) {
            $attributes['visibility'] = $data['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
        }

        $item->update($attributes);

        return $this->ok($this->data($item));
    }

    /**
     * Pages and tasks stay; they only leave the space.
     */
    public function destroy(int $space): JsonResponse
    {
        $this->find($this->household(), $space)->delete();

        return $this->ok();
    }

    /**
     * Adds a task straight into the space (one line of natural language, like the quick entry).
     */
    public function addTask(Request $request, int $space): JsonResponse
    {
        $household = $this->household();
        $item = $this->find($household, $space);
        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);
        $task = QuickCapture::task($this->user(), $household->id, $data['text']);
        abort_if($task === null, 422, __('Please enter a title.'));
        $task->forceFill(['space_id' => $item->id])->save();

        return $this->ok(['id' => $task->id, 'title' => $task->title], 201);
    }

    /**
     * Puts a task into a space, or out of it (space null).
     */
    public function assignTask(Request $request, int $task): JsonResponse
    {
        $household = $this->household();
        $data = $request->validate(['space' => ['present', 'nullable', 'integer']]);
        $item = Item::query()->forHousehold($household->id)->visibleTo($this->user())->where('collection_id', Collection::tasksFor($household->id)->id)->find($task) ?? abort(404);
        $space = $data['space'] !== null ? $this->find($household, (int) $data['space']) : null;
        $item->forceFill(['space_id' => $space?->id])->save();

        return $this->ok(['id' => $item->id, 'space' => $space?->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:60'],
            'icon' => ['sometimes', Rule::in(Space::ICONS)],
            'private' => ['sometimes', 'boolean'],
        ]);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function find(Household $household, int $id): Space
    {
        return Space::query()->visibleTo($this->user(), $household->id)->find($id) ?? abort(404);
    }

    /**
     * @return array{id: int, name: string, icon: string, private: bool, own: bool}
     */
    private function data(Space $space): array
    {
        return ['id' => $space->id, 'name' => $space->name, 'icon' => $space->icon, 'private' => $space->isPrivate(), 'own' => $space->created_by === $this->user()->id];
    }
}
