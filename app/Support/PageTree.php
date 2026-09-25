<?php

namespace App\Support;

use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * In-memory hierarchy of pages (notes) built from one query.
 *
 * A page whose parent is not part of the given set (e.g. someone else's private
 * page) is treated as a root, so every page the viewer can see stays reachable.
 */
class PageTree
{
    /**
     * @var Collection<int, Item>
     */
    private Collection $pagesById;

    /**
     * @var array<int|string, array<int, Item>>
     */
    private array $childrenByParent = [];

    /**
     * @param  Collection<int, Item>  $pages
     */
    public function __construct(Collection $pages)
    {
        $this->pagesById = $pages->keyBy('id');

        $sorted = $pages->sortBy(fn (Item $page) => mb_strtolower($page->title))->values();

        foreach ($sorted as $page) {
            $parentKey = $page->parent_id !== null && $this->pagesById->has($page->parent_id)
                ? $page->parent_id
                : 'root';

            $this->childrenByParent[$parentKey][] = $page;
        }
    }

    public function find(int $id): ?Item
    {
        return $this->pagesById->get($id);
    }

    /**
     * @return array<int, Item>
     */
    public function roots(): array
    {
        return $this->childrenByParent['root'] ?? [];
    }

    /**
     * @return array<int, Item>
     */
    public function children(int $id): array
    {
        return $this->childrenByParent[$id] ?? [];
    }

    /**
     * Ancestors of the page, outermost first (the page itself excluded).
     *
     * @return array<int, Item>
     */
    public function ancestors(int $id): array
    {
        $ancestors = [];
        $visited = [$id => true];
        $current = $this->find($id);

        while ($current && $current->parent_id !== null && ! isset($visited[$current->parent_id])) {
            $parent = $this->find($current->parent_id);

            if (! $parent) {
                break;
            }

            $visited[$parent->id] = true;
            array_unshift($ancestors, $parent);
            $current = $parent;
        }

        return $ancestors;
    }

    /**
     * @return array<int, int>
     */
    public function descendantIds(int $id): array
    {
        $ids = [];
        $stack = [$id];

        while ($stack !== []) {
            foreach ($this->children(array_pop($stack)) as $child) {
                if (! in_array($child->id, $ids, true) && $child->id !== $id) {
                    $ids[] = $child->id;
                    $stack[] = $child->id;
                }
            }
        }

        return $ids;
    }

    /**
     * Depth-first list for indented selects.
     *
     * @return array<int, array{page: Item, depth: int}>
     */
    public function flatten(): array
    {
        $rows = [];
        $visited = [];

        $walk = function (array $pages, int $depth) use (&$walk, &$rows, &$visited) {
            foreach ($pages as $page) {
                if (isset($visited[$page->id])) {
                    continue;
                }

                $visited[$page->id] = true;
                $rows[] = ['page' => $page, 'depth' => $depth];
                $walk($this->children($page->id), $depth + 1);
            }
        };

        $walk($this->roots(), 0);

        return $rows;
    }
}
