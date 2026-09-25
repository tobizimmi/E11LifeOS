<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DataExportController extends Controller
{
    /**
     * Export all data associated with the current user's account and household
     * as a single JSON download (GDPR Art. 15/20: right of access & portability).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $household = $user->currentHousehold();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'user' => $user->only(['id', 'name', 'email', 'created_at']),
            'household' => $household?->only(['id', 'name', 'created_at']),
            'household_members' => $household?->members()->with('user:id,name,email')->get()
                ->map->only(['role', 'joined_at', 'user']),
            'notes' => $this->exportItems($household, $user, Item::TYPE_NOTE, ['id', 'parent_id', 'icon', 'title', 'content', 'visibility', 'created_at', 'updated_at']),
            'todos' => $this->exportItems($household, $user, Item::TYPE_TODO, ['id', 'title', 'done', 'position', 'visibility', 'created_at', 'updated_at']),
            'inbox' => $this->exportItems($household, $user, Item::TYPE_INBOX, ['id', 'title', 'visibility', 'created_at', 'updated_at']),
            'shopping_list' => $household
                ? ShoppingListEntry::where('household_id', $household->id)->get()
                    ->map->only(['id', 'label', 'quantity', 'checked', 'created_at', 'updated_at'])
                : [],
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="e11lifeos-export-'.$user->id.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Items the user can see (shared ones plus their own private ones — never
     * other members' private items), with tag names inlined.
     *
     * @param  array<int, string>  $attributes
     * @return Collection<int, array<string, mixed>>|array{}
     */
    private function exportItems(?Household $household, User $user, string $type, array $attributes): Collection|array
    {
        if (! $household) {
            return [];
        }

        return Item::query()
            ->forHousehold($household->id)
            ->visibleTo($user)
            ->ofType($type)
            ->with('tags')
            ->orderBy('id')
            ->get()
            ->map(fn (Item $item) => $item->only($attributes) + ['tags' => $item->tags->pluck('name')->sort()->values()->all()]);
    }
}
