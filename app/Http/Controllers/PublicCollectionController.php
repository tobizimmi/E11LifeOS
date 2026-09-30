<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\User;
use App\Support\PropertyDisplay;
use Illuminate\View\View;

/**
 * Read-only view of a collection for people outside the household (link with a random token,
 * no login). Private entries of other members are never shown; links to other nodes are left out.
 */
class PublicCollectionController extends Controller
{
    public function __invoke(string $token): View
    {
        $collection = strlen($token) >= 32 ? Collection::with('properties')->whereShareToken('public_token', $token, 'public_expires_at')->first() : null;

        abort_unless($collection && ! $collection->isTasks(), 404);

        $properties = $collection->properties
            ->reject(fn (CollectionProperty $property) => $property->type === CollectionProperty::TYPE_RELATION)
            ->sortBy('position')->values();
        $entries = Item::query()->where('collection_id', $collection->id)
            ->when(! $collection->isPrivate(), fn ($query) => $query->where('visibility', Item::VISIBILITY_SHARED))
            ->orderBy('position')->orderBy('id')->get();
        $members = User::whereIn('id', HouseholdMember::where('household_id', $collection->household_id)->pluck('user_id'))->pluck('name', 'id')->all();

        return view('collections.public', [
            'collection' => $collection,
            'properties' => $properties,
            'rows' => $entries->map(fn (Item $entry) => [
                'entry' => $entry,
                'values' => $properties->mapWithKeys(fn (CollectionProperty $property) => [$property->id => PropertyDisplay::format($property, $entry, $members)])->all(),
            ]),
        ]);
    }
}
