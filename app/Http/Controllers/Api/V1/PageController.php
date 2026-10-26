<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API for pages / notes (the "second brain"): list, read and edit a page's Markdown body
 * offline. A page is an {@see Item} of type note; its text lives in content.body as Markdown.
 */
class PageController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok(
            $this->notes()->orderByDesc('updated_at')->get()
                ->map(fn (Item $page) => $this->listData($page))->all()
        );
    }

    public function show(Item $page): JsonResponse
    {
        $this->authorizePage($page);

        return $this->ok($this->pageData($page));
    }

    public function store(Request $request): JsonResponse
    {
        $household = $this->household();
        $data = $this->validatePage($request);

        $page = Item::create([
            'household_id' => $household->id,
            'type' => Item::TYPE_NOTE,
            'visibility' => Item::VISIBILITY_SHARED,
            'title' => $data['title'],
            'icon' => $data['icon'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'content' => ['body' => $data['body'] ?? ''],
            'created_by' => $this->user()->id,
            'position' => (int) Item::where('household_id', $household->id)->ofType(Item::TYPE_NOTE)->max('position') + 1,
        ]);

        return $this->ok($this->pageData($page), 201);
    }

    public function update(Request $request, Item $page): JsonResponse
    {
        $this->authorizePage($page);
        $data = $this->validatePage($request, required: false);

        if (array_key_exists('title', $data)) {
            $page->title = $data['title'];
        }
        if (array_key_exists('icon', $data)) {
            $page->icon = $data['icon'];
        }
        if (array_key_exists('body', $data)) {
            $page->content = ['body' => (string) $data['body']] + ($page->content ?? []);
        }

        $page->save();

        return $this->ok($this->pageData($page->fresh()));
    }

    public function destroy(Item $page): JsonResponse
    {
        $this->authorizePage($page);
        abort_if($this->notes()->where('parent_id', $page->id)->exists(), 422, __('Please move or delete the sub-pages first.'));
        $page->delete();

        return $this->ok(['id' => $page->id]);
    }

    /**
     * @return array{title?: string, icon?: ?string, parent_id?: ?int, body?: ?string}
     */
    private function validatePage(Request $request, bool $required = true): array
    {
        return $request->validate([
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:16'],
            'parent_id' => ['nullable', 'integer'],
            'body' => ['nullable', 'string', 'max:100000'],
        ]);
    }

    /**
     * @return array{id: int, title: string, icon: ?string, parent_id: ?int, updated_at: ?string}
     */
    private function listData(Item $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'icon' => $page->icon,
            'parent_id' => $page->parent_id !== null ? (int) $page->parent_id : null,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, title: string, icon: ?string, parent_id: ?int, body: string, updated_at: ?string}
     */
    private function pageData(Item $page): array
    {
        return $this->listData($page) + ['body' => $page->body()];
    }

    /**
     * @return Builder<Item>
     */
    private function notes()
    {
        return Item::query()->forHousehold($this->household()->id)->visibleTo($this->user())->ofType(Item::TYPE_NOTE);
    }

    private function authorizePage(Item $page): void
    {
        abort_unless($this->notes()->whereKey($page->id)->exists(), 404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
