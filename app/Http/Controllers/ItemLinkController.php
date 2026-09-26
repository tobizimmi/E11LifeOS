<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stable link to any item (/items/{id}) – used by links in texts and relation properties –
 * forwarding to where the item is shown: its page, its collection entry or the inbox.
 */
class ItemLinkController extends Controller
{
    public function __invoke(Request $request, int $item): RedirectResponse
    {
        $household = $request->user()->currentHousehold();
        $model = $household
            ? Item::query()->forHousehold($household->id)->visibleTo($request->user())->find($item)
            : null;

        abort_unless($model, 404);

        return match ($model->type) {
            Item::TYPE_NOTE => redirect()->route('pages.index', ['item' => $model->id]),
            Item::TYPE_INBOX => redirect()->route('inbox.index'),
            Item::TYPE_TODO => redirect()->route('tasks.index', ['task' => $model->id]),
            default => $model->collection_id
                ? redirect()->route('collections.show', ['collection' => $model->collection_id, 'entry' => $model->id])
                : redirect()->route('dashboard'),
        };
    }
}
