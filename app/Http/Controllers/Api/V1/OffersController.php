<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Models\ShoppingStore;
use App\Models\ShoppingWatch;
use App\Support\Shopping\Offers;
use App\Support\Shopping\OffersUnavailable;
use App\Support\ShoppingLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Supermarket offers for the app: current offers near the household's postcode for free searches,
 * the watch list and the items on the shopping list – and putting an offer on the list.
 */
class OffersController extends ApiController
{
    public function settings(): JsonResponse
    {
        return $this->ok($this->settingsData());
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'zip' => ['nullable', 'regex:/^\d{5}$/'],
            'chains' => ['sometimes', 'array'],
            'chains.*' => [Rule::in(array_keys($this->chains()))],
        ]);

        // The chains are stored as a JSON string through the query builder, like the web does.
        Household::whereKey($this->household()->id)->update([
            'offers_zip' => ($data['zip'] ?? null) ?: null,
            'offers_chains' => json_encode(array_values($data['chains'] ?? [])),
        ]);

        return $this->ok($this->settingsData());
    }

    public function storeWatch(Request $request): JsonResponse
    {
        $data = $request->validate(['term' => ['required', 'string', 'min:2', 'max:60']]);
        ShoppingWatch::firstOrCreate(['household_id' => $this->household()->id, 'term' => trim($data['term'])], ['created_by' => $this->user()->id]);

        return $this->ok($this->settingsData(), 201);
    }

    public function destroyWatch(int $watch): JsonResponse
    {
        ShoppingWatch::where('household_id', $this->household()->id)->whereKey($watch)->delete();

        return $this->ok($this->settingsData());
    }

    /**
     * Offers for a search term (`q`), for what is on the shopping list (`for_list=1`) and for every
     * watched term. Answers 503 when the offers service cannot be reached.
     */
    public function index(Request $request, Offers $offers): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:60'], 'for_list' => ['nullable', 'boolean']]);
        $household = $this->household();
        $zip = (string) $household->offers_zip;
        $chains = $this->chainKeys($household);

        if (! preg_match('/^\d{5}$/', $zip)) {
            return $this->ok(['zip_missing' => true, 'sections' => []]);
        }

        $sections = [];

        try {
            if (trim((string) ($data['q'] ?? '')) !== '') {
                $sections[] = ['title' => $data['q'], 'kind' => 'search', 'offers' => $offers->search($data['q'], $zip, $chains)];
            }

            if ($data['for_list'] ?? false) {
                // Capped at six items: each uncached term is an external call.
                ShoppingListEntry::where('shopping_list_id', ShoppingList::defaultFor($household->id)->id)->where('checked', false)->orderBy('label')->limit(6)->pluck('label')
                    ->each(function (string $label) use (&$sections, $offers, $zip, $chains) {
                        $found = $offers->search($label, $zip, $chains, 6);

                        if ($found !== []) {
                            $sections[] = ['title' => $label, 'kind' => 'list', 'offers' => $found];
                        }
                    });
            }

            foreach (ShoppingWatch::where('household_id', $household->id)->orderBy('term')->get() as $watch) {
                $sections[] = ['title' => $watch->term, 'kind' => 'watch', 'watch_id' => $watch->id, 'offers' => $offers->search($watch->term, $zip, $chains, 12)];
            }
        } catch (OffersUnavailable $exception) {
            return response()->json(['success' => false, 'error' => $exception->getMessage()], 503);
        }

        return $this->ok(['zip_missing' => false, 'sections' => $sections]);
    }

    /**
     * Puts an offer on the household's shopping list, with store, price and end date as the note.
     */
    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'brand' => ['nullable', 'string', 'max:100'],
            'store' => ['nullable', 'string', 'max:60'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'unit' => ['nullable', 'string', 'max:60'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $title = trim($data['title']);
        $brand = trim((string) ($data['brand'] ?? ''));
        $label = $brand !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($brand)) ? $brand.' '.$title : $title;
        $validTo = ($data['valid_to'] ?? null) ? Carbon::parse($data['valid_to'])->format('d.m.') : null;
        $note = trim(__('Offer').' '.($data['store'] ?? '').($validTo ? ' '.__('until :date', ['date' => $validTo]) : ''));

        $entry = ShoppingLists::add(ShoppingList::defaultFor($this->household()->id), $label, $data['unit'] ?? null, $this->user()->id, [
            'note' => $note,
            'price' => isset($data['price']) ? round((float) $data['price'], 2) : null,
        ]);

        return $this->ok(['id' => $entry->id, 'label' => $entry->label], 201);
    }

    /**
     * @return array<string, array{name: string, flyer: string}>
     */
    private function chains(): array
    {
        return collect(ShoppingStore::CHAINS)->except('other')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsData(): array
    {
        $household = $this->household();

        return [
            'zip' => $household->offers_zip,
            'chains' => $this->chainKeys($household->refresh()),
            'all_chains' => collect($this->chains())->map(fn (array $chain, string $key) => ['key' => $key, 'name' => $chain['name']])->values()->all(),
            'watches' => ShoppingWatch::where('household_id', $household->id)->orderBy('term')->get()->map(fn ($watch) => ['id' => $watch->getKey(), 'term' => $watch->getAttribute('term')])->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function chainKeys(Household $household): array
    {
        $chains = $household->getAttribute('offers_chains');

        return is_array($chains) ? array_values(array_map('strval', $chains)) : [];
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
