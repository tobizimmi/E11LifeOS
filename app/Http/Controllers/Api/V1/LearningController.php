<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use App\Models\Learning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lessons learned for the app: the library and the spaced-repetition review of what is due.
 */
class LearningController extends ApiController
{
    public function index(): JsonResponse
    {
        $lessons = Learning::query()->visibleTo($this->user(), $this->household()->id)->latest()->get();

        return $this->ok([
            'kinds' => $this->options(Learning::kinds()),
            'areas' => $this->options(Learning::areas()),
            'due' => $lessons->whereNull('archived_at')->filter(fn (Learning $lesson) => $lesson->due_on !== null && $lesson->due_on->lte(today()))->count(),
            'lessons' => $lessons->map(fn (Learning $lesson) => $this->lessonData($lesson))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $lesson = Learning::create($this->attributes($data) + [
            'household_id' => $this->household()->id,
            'created_by' => $this->user()->id,
            'visibility' => ($data['shared'] ?? true) ? Item::VISIBILITY_SHARED : Item::VISIBILITY_PRIVATE,
            'interval_days' => 1,
            'due_on' => today()->addDay(),
        ]);

        return $this->ok($this->lessonData($lesson->refresh()), 201);
    }

    public function update(Request $request, int $lesson): JsonResponse
    {
        $model = $this->find($lesson);
        $data = $this->validated($request);
        $attributes = $this->attributes($data);

        if (array_key_exists('shared', $data) && $model->created_by === $this->user()->id) {
            $attributes['visibility'] = $data['shared'] ? Item::VISIBILITY_SHARED : Item::VISIBILITY_PRIVATE;
        }

        $model->update($attributes);

        return $this->ok($this->lessonData($model));
    }

    /**
     * Records a review: "again" starts over tomorrow, "good" and "easy" stretch the interval.
     */
    public function review(Request $request, int $lesson): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', Rule::in(['again', 'good', 'easy'])]]);
        $model = $this->find($lesson);
        $model->review($data['rating']);

        return $this->ok($this->lessonData($model->refresh()));
    }

    public function applied(int $lesson): JsonResponse
    {
        $model = $this->find($lesson);
        $model->update(['applied_count' => $model->applied_count + 1]);

        return $this->ok($this->lessonData($model));
    }

    public function archive(int $lesson): JsonResponse
    {
        $model = $this->find($lesson);
        $model->update(['archived_at' => $model->archived_at ? null : now()]);

        return $this->ok($this->lessonData($model));
    }

    public function destroy(int $lesson): JsonResponse
    {
        $this->find($lesson)->delete();

        return $this->ok();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:5000'],
            'kind' => ['required', Rule::in(array_keys(Learning::kinds()))],
            'area' => ['required', Rule::in(array_keys(Learning::areas()))],
            'source' => ['nullable', 'string', 'max:255'],
            'shared' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'title' => trim($data['title']),
            'body' => trim((string) ($data['body'] ?? '')) ?: null,
            'kind' => $data['kind'],
            'area' => $data['area'],
            'source' => trim((string) ($data['source'] ?? '')) ?: null,
        ];
    }

    /**
     * @param  array<string, array{label: string, icon: string}>  $options
     * @return list<array<string, string>>
     */
    private function options(array $options): array
    {
        return collect($options)->map(fn (array $option, string $key) => ['key' => $key] + $option)->values()->all();
    }

    private function find(int $id): Learning
    {
        return Learning::query()->visibleTo($this->user(), $this->household()->id)->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonData(Learning $lesson): array
    {
        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'body' => $lesson->body,
            'kind' => $lesson->kind,
            'area' => $lesson->area,
            'source' => $lesson->source,
            'interval_days' => $lesson->interval_days,
            'due_on' => $lesson->due_on?->toDateString(),
            'due' => $lesson->archived_at === null && $lesson->due_on !== null && $lesson->due_on->lte(today()),
            'review_count' => $lesson->review_count,
            'applied_count' => $lesson->applied_count,
            'archived' => $lesson->archived_at !== null,
            'shared' => ! $lesson->isPrivate(),
            'mine' => $lesson->created_by === $this->user()->id,
        ];
    }
}
