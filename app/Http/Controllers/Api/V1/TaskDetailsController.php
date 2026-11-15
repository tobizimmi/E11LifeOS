<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Collection;
use App\Models\Household;
use App\Models\Item;
use App\Models\Space;
use App\Support\Links;
use App\Support\QuickCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Everything a task has besides title, date and priority: notes, time, repeat, assignee, category,
 * tags, subtasks (checklist), rotation and linked pages. Mirrors the task panel of the web app.
 */
class TaskDetailsController extends ApiController
{
    private const MAX_SUBTASKS = 100;

    /**
     * Categories and members for filters and pickers.
     */
    public function options(): JsonResponse
    {
        $household = $this->household();
        $collection = Collection::tasksFor($household->id)->load('properties');
        $members = QuickCapture::members($household->id);

        return $this->ok([
            'category' => array_map(fn (array $option) => ['id' => $option['id'], 'name' => $option['name']], $collection->systemProperty('category')->selectOptions()),
            'members' => array_map(fn (int $id, string $name) => ['id' => $id, 'name' => $name], array_keys($members), array_values($members)),
            'me' => $this->user()->id,
        ]);
    }

    public function show(int $task): JsonResponse
    {
        return $this->ok($this->detail($this->find($task)));
    }

    public function update(Request $request, int $task): JsonResponse
    {
        $item = $this->find($task);
        $collection = Collection::tasksFor($item->household_id)->load('properties');
        $members = array_keys(QuickCapture::members($item->household_id));

        $data = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'time' => ['sometimes', 'nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'repeat' => ['sometimes', 'nullable', 'string', 'max:20'],
            'assignee' => ['sometimes', 'nullable', 'integer'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['string', 'max:40'],
        ]);

        if (array_key_exists('notes', $data)) {
            $content = $item->content ?? [];
            $content['body'] = (string) ($data['notes'] ?? '');
            $item->content = $content;
        }

        if (array_key_exists('time', $data)) {
            $item->due_time = $data['time'];
            $due = $collection->systemProperty('due');

            if ($data['time'] !== null && $due !== null && ! $item->dueDate()) {
                $item->setPropertyValue($due, now()->format('H:i') < $data['time'] ? today()->toDateString() : today()->addDay()->toDateString());
            }
        }

        foreach (['repeat' => 'repeat', 'category' => 'category'] as $field => $key) {
            $property = $collection->systemProperty($key);

            if (array_key_exists($field, $data) && $property !== null) {
                $value = $data[$field] === '' ? null : $data[$field];
                $item->setPropertyValue($property, $property->normalizeValue($value, $members));
            }
        }

        $assignee = $collection->systemProperty('assignee');

        if (array_key_exists('assignee', $data) && $assignee !== null) {
            abort_if($data['assignee'] !== null && ! in_array((int) $data['assignee'], $members, true), 422);
            $item->setPropertyValue($assignee, $data['assignee'] !== null ? (int) $data['assignee'] : null);
        }

        $item->save();

        if (array_key_exists('tags', $data)) {
            $item->syncTagNames($data['tags']);
        }

        return $this->ok($this->detail($item->refresh()));
    }

    public function addSubtask(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:255']]);
        $item = $this->find($task);
        $subtasks = $item->subtasks();
        abort_if(count($subtasks) >= self::MAX_SUBTASKS, 422, __('Too many entries.'));

        $subtasks[] = ['id' => Str::random(8), 'text' => trim($data['text']), 'done' => false];
        $item->setSubtasks($subtasks);
        $item->save();

        return $this->ok($this->detail($item), 201);
    }

    public function toggleSubtask(int $task, string $subtask): JsonResponse
    {
        $item = $this->find($task);
        $item->setSubtasks(array_map(fn (array $entry) => $entry['id'] === $subtask ? array_merge($entry, ['done' => ! $entry['done']]) : $entry, $item->subtasks()));
        $item->save();

        return $this->ok($this->detail($item));
    }

    public function destroySubtask(int $task, string $subtask): JsonResponse
    {
        $item = $this->find($task);
        $item->setSubtasks(array_filter($item->subtasks(), fn (array $entry) => $entry['id'] !== $subtask));
        $item->save();

        return $this->ok($this->detail($item));
    }

    public function toggleRotation(int $task, int $member): JsonResponse
    {
        $item = $this->find($task);
        abort_unless(array_key_exists($member, QuickCapture::members($item->household_id)), 404);

        $rotation = $item->rotation();
        $item->setRotation(in_array($member, $rotation, true) ? array_values(array_diff($rotation, [$member])) : [...$rotation, $member]);
        $assignee = Collection::tasksFor($item->household_id)->load('properties')->systemProperty('assignee');

        if ($assignee !== null && count($item->rotation()) > 1 && ! in_array((int) $item->propertyValue($assignee), $item->rotation(), true)) {
            $item->setPropertyValue($assignee, $item->rotation()[0]);
        }

        $item->save();

        return $this->ok($this->detail($item));
    }

    /**
     * Pages linked to the task (manual links).
     */
    public function linkPage(Request $request, int $task): JsonResponse
    {
        $data = $request->validate(['page' => ['required', 'integer']]);
        $item = $this->find($task);
        $page = Item::query()->forHousehold($item->household_id)->visibleTo($this->user())->where('type', Item::TYPE_NOTE)->find($data['page']) ?? abort(404);
        Links::addManual(Links::ITEM, $item->id, Links::ITEM, $page->id, $item->household_id, $this->user()->id);

        return $this->ok($this->detail($item), 201);
    }

    public function unlinkPage(int $task, int $page): JsonResponse
    {
        $item = $this->find($task);
        Links::removeManual(Links::ITEM, $item->id, Links::ITEM, $page);

        return $this->ok($this->detail($item));
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function find(int $id): Item
    {
        $household = $this->household();

        return Item::query()->forHousehold($household->id)->visibleTo($this->user())
            ->where('collection_id', Collection::tasksFor($household->id)->id)->find($id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Item $task): array
    {
        $collection = Collection::tasksFor($task->household_id)->load('properties');
        $members = QuickCapture::members($task->household_id);
        $value = fn (string $key) => ($property = $collection->systemProperty($key)) ? $task->propertyValue($property) : null;
        $options = fn (string $key) => array_map(fn (array $option) => ['id' => $option['id'], 'name' => $option['name']], $collection->systemProperty($key)->selectOptions());
        $assignee = $value('assignee');
        $overview = Links::overview(Links::ITEM, $task->id, $this->user(), $task->household_id);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'done' => (bool) $task->done,
            'due' => $task->dueDate(),
            'time' => $task->due_time,
            'priority' => $value('priority') !== null ? (string) $value('priority') : null,
            'repeat' => $value('repeat') !== null ? (string) $value('repeat') : null,
            'category' => $value('category') !== null ? (string) $value('category') : null,
            'assignee' => $assignee !== null ? ['id' => (int) $assignee, 'name' => $members[(int) $assignee] ?? null] : null,
            'space' => $task->space_id,
            'notes' => $task->body(),
            'tags' => $task->tags()->pluck('name')->all(),
            'subtasks' => array_map(fn (array $entry) => ['id' => (string) $entry['id'], 'text' => (string) $entry['text'], 'done' => $entry['done']], $task->subtasks()),
            'rotation' => $task->rotation(),
            'pages' => collect($overview['linked'])->filter(fn (array $node) => ($node['page'] ?? false) === true)->map(fn (array $node) => ['id' => $node['id'] ?? null, 'title' => $node['title'] ?? '', 'removable' => (bool) ($node['removable'] ?? false)])->values()->all(),
            'options' => [
                'repeat' => $options('repeat'),
                'category' => $options('category'),
                'spaces' => Space::query()->visibleTo($this->user(), $task->household_id)->get()->map(fn (Space $space) => ['id' => $space->id, 'name' => $space->name, 'icon' => $space->icon])->all(),
                'members' => array_map(fn (int $id, string $name) => ['id' => $id, 'name' => $name], array_keys($members), array_values($members)),
            ],
        ];
    }
}
