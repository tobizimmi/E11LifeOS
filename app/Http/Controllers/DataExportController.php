<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ShoppingListEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'notes' => $household
                ? Item::where('household_id', $household->id)->ofType(Item::TYPE_NOTE)->get()
                    ->map->only(['id', 'title', 'content', 'tags', 'created_at', 'updated_at'])
                : [],
            'todos' => $household
                ? Item::where('household_id', $household->id)->ofType(Item::TYPE_TODO)->get()
                    ->map->only(['id', 'title', 'done', 'position', 'created_at', 'updated_at'])
                : [],
            'shopping_list' => $household
                ? ShoppingListEntry::where('household_id', $household->id)->get()
                    ->map->only(['id', 'label', 'quantity', 'checked', 'created_at', 'updated_at'])
                : [],
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="e11lifeos-export-'.$user->id.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
