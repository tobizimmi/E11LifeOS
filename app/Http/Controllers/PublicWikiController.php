<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Support\NoteMarkdown;
use App\Support\PageTree;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Read-only view of a published wiki page and its shared subtree for people outside the household
 * (link with a random token, no login). Private subpages are never shown; the whole subtree is
 * navigable through a tree sidebar. Links between published pages stay inside the public link.
 */
class PublicWikiController extends Controller
{
    public function __invoke(Request $request, string $token): View
    {
        $root = strlen($token) >= 32
            ? Item::query()->whereShareToken('public_token', $token, 'public_expires_at')->where('type', Item::TYPE_NOTE)->first()
            : null;

        abort_unless($root instanceof Item, 404);

        // The published subtree: the root plus its descendants that are shared with the household.
        $householdPages = Item::query()
            ->where('household_id', $root->household_id)
            ->where('type', Item::TYPE_NOTE)
            ->where(fn ($query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('id', $root->id))
            ->get(['id', 'parent_id', 'title', 'icon', 'visibility', 'position', 'content']);

        $subtreeIds = array_merge([$root->id], (new PageTree($householdPages))->descendantIds($root->id));

        /** @var Collection<int, Item> $subtreePages */
        $subtreePages = $householdPages->whereIn('id', $subtreeIds)->values();
        $tree = new PageTree($subtreePages);

        $requested = (int) $request->query('p', (string) $root->id);
        $current = $subtreePages->firstWhere('id', $requested) ?? $root;

        $idsByTitle = [];

        foreach ($subtreePages as $page) {
            $idsByTitle[mb_strtolower($page->title)] = $page->id;
        }

        return view('pages.public', [
            'token' => $token,
            'root' => $root,
            'current' => $current,
            'rows' => $tree->flatten(),
            'breadcrumbs' => $tree->ancestors($current->id),
            'html' => NoteMarkdown::publicToHtml($current, $token, $idsByTitle),
        ]);
    }
}
