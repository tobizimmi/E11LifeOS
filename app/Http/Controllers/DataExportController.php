<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\BankAccount;
use App\Models\Collection;
use App\Models\FinanceRule;
use App\Models\Household;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\ShoppingListEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;

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
            'todos' => $this->exportItems($household, $user, Item::TYPE_TODO, ['id', 'collection_id', 'title', 'content', 'done', 'properties', 'position', 'visibility', 'created_at', 'updated_at']),
            'inbox' => $this->exportItems($household, $user, Item::TYPE_INBOX, ['id', 'title', 'visibility', 'created_at', 'updated_at']),
            'collections' => $household
                ? Collection::query()->visibleTo($user, $household->id)->with('properties')->orderBy('id')->get()
                    ->map(fn (Collection $collection) => $collection->only(['id', 'name', 'icon', 'visibility', 'system_key', 'created_at']) + [
                        'properties' => $collection->properties->map->only(['id', 'name', 'type', 'options'])->all(),
                    ])
                : [],
            'collection_entries' => $this->exportItems($household, $user, Item::TYPE_ENTRY, ['id', 'collection_id', 'title', 'content', 'properties', 'visibility', 'created_at', 'updated_at']),
            'recipes' => $household
                ? Recipe::query()->visibleTo($user, $household->id)->with(['categories', 'tags', 'ratings' => fn ($query) => $query->where('user_id', $user->id)])->orderBy('id')->get()
                    ->map(fn (Recipe $recipe) => $recipe->only([
                        'id', 'title', 'description', 'ingredients', 'instructions', 'prep_time', 'cook_time', 'servings',
                        'difficulty', 'source_url', 'source_type', 'nutrition', 'visibility', 'cooked_count', 'archived_at', 'created_at', 'updated_at',
                    ]) + [
                        'categories' => $recipe->categories->pluck('name')->all(),
                        'tags' => $recipe->tags->pluck('name')->sort()->values()->all(),
                        'favorite' => $recipe->isFavoriteOf($user),
                        'additional_photos' => count($recipe->additional_images ?? []),
                        'my_rating' => $recipe->ratings->first()?->only(['rating', 'comment', 'cooked_at', 'updated_at']),
                    ])
                : [],
            'meal_plan' => $household
                ? MealPlan::query()->visibleTo($user, $household->id)->orderBy('date')->orderBy('id')->get()
                    ->map->only(['id', 'date', 'meal_type', 'recipe_id', 'title', 'servings', 'note', 'visibility', 'created_at'])
                : [],
            'attachments' => $household
                ? Attachment::where('household_id', $household->id)->where('created_by', $user->id)->orderBy('id')->get()
                    ->map(fn (Attachment $attachment) => $attachment->only(['id', 'item_id', 'original_name', 'mime_type', 'size', 'created_at']) + ['url' => $attachment->url()])
                : [],
            'shopping_list' => $household
                ? ShoppingListEntry::where('household_id', $household->id)->get()
                    ->map->only(['id', 'label', 'quantity', 'checked', 'created_at', 'updated_at'])
                : [],
            'bank_accounts' => BankAccount::where('user_id', $user->id)->with(['transactions' => fn ($query) => $query->orderBy('booked_on')])->orderBy('id')->get()
                ->map(fn (BankAccount $account) => $account->only(['id', 'name', 'iban', 'created_at']) + [
                    'transactions' => $account->transactions->map->only(['booked_on', 'amount', 'counterparty', 'purpose', 'category'])->all(),
                ]),
            'finance_rules' => FinanceRule::where('user_id', $user->id)->orderBy('pattern')->get()->map->only(['pattern', 'category']),
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
     * @return SupportCollection<int, array<string, mixed>>|array{}
     */
    private function exportItems(?Household $household, User $user, string $type, array $attributes): SupportCollection|array
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
