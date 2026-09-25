<?php

namespace App\Support;

use App\Models\CollectionProperty;
use App\Models\Household;
use App\Models\Item;
use App\Models\Link;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Links between everything in a household: pages, tasks, collection entries, inbox items
 * ("item") and recipes ("recipe"). Links come from texts ([[Page]] or internal links such
 * as /items/12 or /recipes/3), relation properties and the "Link" button; every node shows
 * what it links to and what links to it.
 *
 * A node is shown as array{type: string, id: int, title: string, icon: string, kind: string, url: string, private: bool}.
 */
class Links
{
    public const ITEM = 'item';

    public const RECIPE = 'recipe';

    /**
     * Internal link targets inside a text; group 1 is the type path, group 2 the id.
     */
    public const URL_PATTERN = '#/(items|recipes)/(\d+)(?![\d/])#';

    public static function url(string $type, int $id): string
    {
        return $type === self::RECIPE ? route('recipes.show', $id) : route('items.show', $id);
    }

    public static function key(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    /**
     * "item:12" → ["item", 12]; null for anything else.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parseKey(mixed $key): ?array
    {
        return is_string($key) && preg_match('/^(item|recipe):(\d+)$/', $key, $match) ? [$match[1], (int) $match[2]] : null;
    }

    /**
     * Link targets mentioned in a text: internal URLs and [[Page title]] (pages the author can see).
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function refsInText(string $text, int $householdId, ?User $author): array
    {
        $refs = [];

        preg_match_all(self::URL_PATTERN, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $refs[] = [$match[1] === 'recipes' ? self::RECIPE : self::ITEM, (int) $match[2]];
        }

        preg_match_all(Item::WIKI_LINK_PATTERN, $text, $wikiLinks);
        $titles = array_values(array_unique(array_map(fn (string $title) => mb_strtolower(trim($title)), $wikiLinks[1])));

        if ($titles !== []) {
            Item::query()->forHousehold($householdId)->ofType(Item::TYPE_NOTE)
                ->when($author, fn ($query) => $query->visibleTo($author), fn ($query) => $query->where('visibility', Item::VISIBILITY_SHARED))
                ->get(['id', 'title'])
                ->filter(fn (Item $page) => in_array(mb_strtolower($page->title), $titles, true))
                ->each(function (Item $page) use (&$refs) {
                    $refs[] = [self::ITEM, $page->id];
                });
        }

        return $refs;
    }

    public static function syncBody(Item $item): void
    {
        $author = auth()->user() ?? $item->creator;

        self::sync(self::ITEM, $item->id, $item->household_id, Link::SOURCE_BODY, self::refsInText($item->body(), $item->household_id, $author), $author?->id);
    }

    /**
     * Relation properties of a collection entry.
     */
    public static function syncProperties(Item $item): void
    {
        $refs = [];

        if ($item->collection_id) {
            foreach ($item->collection?->properties ?? [] as $property) {
                if ($property->type === CollectionProperty::TYPE_RELATION) {
                    foreach ((array) $item->propertyValue($property) as $key) {
                        if ($ref = self::parseKey($key)) {
                            $refs[] = $ref;
                        }
                    }
                }
            }
        }

        self::sync(self::ITEM, $item->id, $item->household_id, Link::SOURCE_PROPERTY, $refs, auth()->id());
    }

    public static function addManual(string $fromType, int $fromId, string $toType, int $toId, int $householdId, ?int $userId): bool
    {
        if (($fromType === $toType && $fromId === $toId) || ! self::existsInHousehold($toType, $toId, $householdId)) {
            return false;
        }

        Link::firstOrCreate(
            ['from_type' => $fromType, 'from_id' => $fromId, 'to_type' => $toType, 'to_id' => $toId, 'source' => Link::SOURCE_MANUAL],
            ['household_id' => $householdId, 'created_by' => $userId],
        );

        return true;
    }

    /**
     * Removes a link made with the button, whichever of the two nodes it was made on.
     */
    public static function removeManual(string $type, int $id, string $otherType, int $otherId): void
    {
        Link::where('source', Link::SOURCE_MANUAL)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where(['from_type' => $type, 'from_id' => $id, 'to_type' => $otherType, 'to_id' => $otherId]))
                ->orWhere(fn ($query) => $query->where(['from_type' => $otherType, 'from_id' => $otherId, 'to_type' => $type, 'to_id' => $id])))
            ->delete();
    }

    /**
     * Removes all links from and to a deleted node.
     */
    public static function forget(string $type, int $id): void
    {
        Link::where(fn ($query) => $query->where(['from_type' => $type, 'from_id' => $id]))
            ->orWhere(fn ($query) => $query->where(['to_type' => $type, 'to_id' => $id]))
            ->delete();
    }

    /**
     * What the node links to and what links to it, limited to nodes the viewer can see.
     * Manual links are shown on both sides and can be removed on both.
     *
     * @return array{linked: array<int, array<string, mixed>>, linkedFrom: array<int, array<string, mixed>>}
     */
    public static function overview(string $type, int $id, User $viewer, int $householdId): array
    {
        $outgoing = Link::where(['from_type' => $type, 'from_id' => $id])->get();
        $incoming = Link::where(['to_type' => $type, 'to_id' => $id])->get();

        $nodes = self::nodes(
            [...$outgoing->map(fn (Link $link) => [$link->to_type, $link->to_id]), ...$incoming->map(fn (Link $link) => [$link->from_type, $link->from_id])],
            $viewer,
            $householdId,
        );

        $linked = [];
        $linkedFrom = [];

        foreach ($outgoing as $link) {
            if ($node = $nodes[self::key($link->to_type, $link->to_id)] ?? null) {
                $key = self::key($link->to_type, $link->to_id);
                $linked[$key] ??= $node + ['removable' => false];
                $linked[$key]['removable'] = $linked[$key]['removable'] || $link->source === Link::SOURCE_MANUAL;
            }
        }

        foreach ($incoming as $link) {
            $key = self::key($link->from_type, $link->from_id);

            if (! ($node = $nodes[$key] ?? null)) {
                continue;
            }

            // A manual link belongs to both sides: list it once, under "linked".
            if ($link->source === Link::SOURCE_MANUAL) {
                $linked[$key] ??= $node + ['removable' => true];
                $linked[$key]['removable'] = true;
            } else {
                $linkedFrom[$key] ??= $node;
            }
        }

        $byTitle = fn (array $a, array $b) => strcasecmp($a['title'], $b['title']);
        uasort($linked, $byTitle);
        uasort($linkedFrom, $byTitle);

        return ['linked' => array_values($linked), 'linkedFrom' => array_values($linkedFrom)];
    }

    /**
     * Nodes whose title contains the term (pages, tasks, entries, inbox, recipes), best matches first.
     *
     * @param  array<int, string>  $excludeKeys  e.g. the node itself ("item:12")
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $term, User $viewer, int $householdId, array $excludeKeys = [], int $limit = 12): array
    {
        $term = trim($term);
        $like = '%'.mb_strtolower($term).'%';

        $items = Item::query()->forHousehold($householdId)->visibleTo($viewer)
            ->when($term !== '', fn ($query) => $query->whereRaw('LOWER(items.title) LIKE ?', [$like]))
            ->with('collection')
            ->orderByRaw('LOWER(items.title) = ? DESC', [mb_strtolower($term)])
            ->latest('updated_at')
            ->limit($limit * 2)
            ->get()
            ->map(fn (Item $item) => self::itemNode($item));

        $recipes = self::recipesEnabled($householdId)
            ? Recipe::query()->visibleTo($viewer, $householdId)
                ->when($term !== '', fn ($query) => $query->whereRaw('LOWER(recipes.title) LIKE ?', [$like]))
                ->latest('updated_at')
                ->limit($limit)
                ->get()
                ->map(fn (Recipe $recipe) => self::recipeNode($recipe))
            : collect();

        return $items->concat($recipes)
            ->reject(fn (array $node) => in_array(self::key($node['type'], $node['id']), $excludeKeys, true))
            ->sortBy(fn (array $node) => [mb_strtolower($node['title']) === mb_strtolower($term) ? 0 : (str_starts_with(mb_strtolower($node['title']), mb_strtolower($term)) ? 1 : 2), mb_strtolower($node['title'])])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Nodes for the given references that the viewer can see, keyed "type:id".
     *
     * @param  iterable<int, array{0: string, 1: int}>  $refs
     * @return array<string, array<string, mixed>>
     */
    public static function nodes(iterable $refs, User $viewer, int $householdId): array
    {
        $ids = [self::ITEM => [], self::RECIPE => []];

        foreach ($refs as [$type, $id]) {
            $ids[$type][] = $id;
        }

        $nodes = [];

        if ($ids[self::ITEM] !== []) {
            Item::query()->forHousehold($householdId)->visibleTo($viewer)->whereIn('items.id', array_unique($ids[self::ITEM]))->with('collection')->get()
                ->each(function (Item $item) use (&$nodes) {
                    $nodes[self::key(self::ITEM, $item->id)] = self::itemNode($item);
                });
        }

        if ($ids[self::RECIPE] !== [] && self::recipesEnabled($householdId)) {
            Recipe::query()->visibleTo($viewer, $householdId)->whereIn('recipes.id', array_unique($ids[self::RECIPE]))->get()
                ->each(function (Recipe $recipe) use (&$nodes) {
                    $nodes[self::key(self::RECIPE, $recipe->id)] = self::recipeNode($recipe);
                });
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemNode(Item $item): array
    {
        [$icon, $kind] = match ($item->type) {
            Item::TYPE_NOTE => [$item->displayIcon(), __('Page')],
            Item::TYPE_TODO => ['✅', __('Task')],
            Item::TYPE_INBOX => ['📥', __('Inbox')],
            default => [$item->collection?->displayIcon() ?? '🗂️', $item->collection?->name ?? __('Entry')],
        };

        return ['type' => self::ITEM, 'id' => $item->id, 'title' => $item->title, 'icon' => $icon, 'kind' => $kind, 'url' => self::url(self::ITEM, $item->id), 'private' => $item->isPrivate(), 'page' => $item->type === Item::TYPE_NOTE];
    }

    /**
     * @return array<string, mixed>
     */
    public static function recipeNode(Recipe $recipe): array
    {
        return ['type' => self::RECIPE, 'id' => $recipe->id, 'title' => $recipe->title, 'icon' => '🍳', 'kind' => __('Recipe'), 'url' => self::url(self::RECIPE, $recipe->id), 'private' => $recipe->isPrivate(), 'page' => false];
    }

    /**
     * Replaces the links of one source (text or properties) of a node; targets outside the household are dropped.
     *
     * @param  array<int, array{0: string, 1: int}>  $refs
     */
    private static function sync(string $fromType, int $fromId, int $householdId, string $source, array $refs, ?int $userId): void
    {
        $wanted = collect($refs)
            ->reject(fn (array $ref) => $ref[0] === $fromType && $ref[1] === $fromId)
            ->unique(fn (array $ref) => self::key(...$ref))
            ->filter(fn (array $ref) => self::existsInHousehold($ref[0], $ref[1], $householdId))
            ->keyBy(fn (array $ref) => self::key(...$ref));

        DB::transaction(function () use ($fromType, $fromId, $householdId, $source, $wanted, $userId) {
            $existing = Link::where(['from_type' => $fromType, 'from_id' => $fromId, 'source' => $source])->get()
                ->keyBy(fn (Link $link) => self::key($link->to_type, $link->to_id));

            Link::whereIn('id', $existing->except($wanted->keys()->all())->pluck('id'))->delete();

            foreach ($wanted->except($existing->keys()->all()) as [$type, $id]) {
                Link::create([
                    'household_id' => $householdId, 'from_type' => $fromType, 'from_id' => $fromId,
                    'to_type' => $type, 'to_id' => $id, 'source' => $source, 'created_by' => $userId,
                ]);
            }
        });
    }

    private static function existsInHousehold(string $type, int $id, int $householdId): bool
    {
        return match ($type) {
            self::ITEM => Item::query()->forHousehold($householdId)->whereKey($id)->exists(),
            self::RECIPE => Recipe::where('household_id', $householdId)->whereKey($id)->exists(),
            default => false,
        };
    }

    public static function recipesEnabled(int $householdId): bool
    {
        return (bool) Household::find($householdId)?->hasAddon(Addons::RECIPES);
    }
}
