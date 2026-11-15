<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Bookmark;
use App\Models\BookmarkCategory;
use App\Models\Household;
use App\Models\Item;
use App\Models\Place;
use App\Models\WishlistItem;
use App\Support\BookmarkAi;
use App\Support\BookmarkAreas;
use App\Support\LinkPreview;
use App\Support\Security\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API for "Bookmarks & read later": links with a page preview, comment, area and read state.
 */
class BookmarksController extends ApiController
{
    public function index(): JsonResponse
    {
        $household = $this->household();
        $bookmarks = Bookmark::query()->visibleTo($this->user(), $household->id)->with('category')->orderBy('is_read')->latest('id')->limit(500)->get();
        $categories = BookmarkCategory::where('household_id', $household->id)
            ->withCount(['bookmarks' => fn ($query) => $query->visibleTo($this->user(), $household->id)])
            ->orderBy('position')->orderBy('name')->get();
        $names = $categories->pluck('name')->map(fn (string $name) => mb_strtolower($name));

        return $this->ok([
            'bookmarks' => $bookmarks->map(fn (Bookmark $bookmark) => $this->data($bookmark))->all(),
            'categories' => $categories->map(fn (BookmarkCategory $category) => ['id' => $category->id, 'name' => $category->name, 'icon' => $category->icon, 'color' => $category->color, 'count' => (int) $category->getAttribute('bookmarks_count')])->all(),
            'suggested_areas' => collect(BookmarkAreas::SUGGESTED)->reject(fn (array $area) => $names->contains(mb_strtolower($area['name'])))->values()->all(),
            'ai' => BookmarkAi::isConfigured(),
        ]);
    }

    /**
     * Title, description and preview images of a web address.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);
        abort_unless(UrlGuard::hasValidShape($data['url']), 422, __('Please enter a valid web address (https://…).'));

        try {
            $meta = LinkPreview::fetch($data['url']);
        } catch (\Throwable $exception) {
            return response()->json(['success' => false, 'error' => $exception->getMessage()], 422);
        }

        $match = BookmarkAreas::suggest($data['url'], (string) $meta['title']);

        return $this->ok($meta + ['suggested_area' => $match['name'] ?? null]);
    }

    public function store(Request $request): JsonResponse
    {
        $household = $this->household();
        $bookmark = new Bookmark(['household_id' => $household->id, 'created_by' => $this->user()->id]);
        $this->fill($bookmark, $request, $household);

        return $this->ok($this->data($bookmark->load('category')), 201);
    }

    public function update(Request $request, int $bookmark): JsonResponse
    {
        $household = $this->household();
        $item = $this->find($household, $bookmark);
        $this->fill($item, $request, $household);

        return $this->ok($this->data($item->load('category')));
    }

    public function destroy(int $bookmark): JsonResponse
    {
        $this->find($this->household(), $bookmark)->delete();

        return $this->ok();
    }

    public function toggleRead(int $bookmark): JsonResponse
    {
        $item = $this->find($this->household(), $bookmark);
        $item->update(['is_read' => ! $item->is_read, 'read_at' => $item->is_read ? null : now()]);

        return $this->ok($this->data($item->load('category')));
    }

    public function summarise(int $bookmark): JsonResponse
    {
        abort_unless(BookmarkAi::isConfigured(), 422, __('AI is not set up.'));
        $item = $this->find($this->household(), $bookmark);
        $text = LinkPreview::fetchText($item->url);
        abort_if($text === '', 422, __('The web page could not be loaded for a summary.'));

        try {
            $summary = app(BookmarkAi::class)->summarise((string) $item->title, $text);
        } catch (\Throwable $exception) {
            report($exception);
            abort(422, __('The summary could not be created.'));
        }

        if ($summary !== '') {
            $item->update(['summary' => $summary]);
        }

        return $this->ok($this->data($item->load('category')));
    }

    public function toWishlist(int $bookmark): JsonResponse
    {
        $household = $this->household();
        $item = $this->find($household, $bookmark);

        WishlistItem::create([
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'visibility' => $item->isPrivate() ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => mb_substr($item->title ?: $item->url, 0, 150),
            'url' => $item->url,
            'priority' => 'want',
            'category' => 'other',
            'status' => 'open',
            'reason' => $item->comment,
        ]);

        return $this->ok(null, 201);
    }

    public function toPlace(int $bookmark): JsonResponse
    {
        $household = $this->household();
        $item = $this->find($household, $bookmark);

        Place::create([
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'visibility' => $item->isPrivate() ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'name' => mb_substr($item->title ?: ($item->host() ?? $item->url), 0, 150),
            'url' => mb_substr($item->url, 0, 500),
            'notes' => $item->comment ?: $item->description,
        ]);

        return $this->ok(null, 201);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $household = $this->household();
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'icon' => ['nullable', 'string', 'max:8']]);
        $colors = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777'];
        $count = BookmarkCategory::where('household_id', $household->id)->count();

        $category = BookmarkCategory::firstOrCreate(
            ['household_id' => $household->id, 'name' => trim($data['name'])],
            ['created_by' => $this->user()->id, 'icon' => $data['icon'] ?? null, 'color' => $colors[$count % count($colors)], 'position' => (int) BookmarkCategory::where('household_id', $household->id)->max('position') + 1],
        );

        return $this->ok(['id' => $category->id, 'name' => $category->name, 'icon' => $category->icon, 'color' => $category->color, 'count' => 0], 201);
    }

    public function destroyCategory(int $category): JsonResponse
    {
        BookmarkCategory::where('household_id', $this->household()->id)->findOrFail($category)->delete();

        return $this->ok();
    }

    private function fill(Bookmark $bookmark, Request $request, Household $household): void
    {
        $data = $request->validate([
            'url' => [$bookmark->exists ? 'sometimes' : 'required', 'string', 'max:2048'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'site_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'favicon_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'private' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['url'])) {
            abort_unless(UrlGuard::hasValidShape($data['url']), 422, __('Please enter a valid web address (https://…).'));
            $bookmark->url = trim($data['url']);
        }

        foreach (['title', 'description', 'comment', 'site_name', 'image_url', 'favicon_url'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) ($data[$field] ?? ''));
                $bookmark->{$field} = $value !== '' ? $value : null;
            }
        }

        if (array_key_exists('category_id', $data)) {
            $category = $data['category_id'] !== null ? BookmarkCategory::where('household_id', $household->id)->find($data['category_id']) : null;
            abort_if($data['category_id'] !== null && $category === null, 422);
            $bookmark->category_id = $category?->id;
        }

        if (array_key_exists('private', $data)) {
            $bookmark->visibility = $data['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
        } elseif (! $bookmark->exists) {
            $bookmark->visibility = Item::VISIBILITY_SHARED;
        }

        $bookmark->save();
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function find(Household $household, int $id): Bookmark
    {
        return Bookmark::query()->visibleTo($this->user(), $household->id)->find($id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Bookmark $bookmark): array
    {
        return [
            'id' => $bookmark->id,
            'url' => $bookmark->url,
            'title' => $bookmark->title,
            'description' => $bookmark->description,
            'site' => $bookmark->host(),
            'favicon' => $bookmark->favicon_url,
            'image' => $bookmark->image_url,
            'comment' => $bookmark->comment,
            'summary' => $bookmark->summary,
            'read' => (bool) $bookmark->is_read,
            'private' => $bookmark->isPrivate(),
            'own' => $bookmark->created_by === $this->user()->id,
            'category_id' => $bookmark->category_id,
        ];
    }
}
