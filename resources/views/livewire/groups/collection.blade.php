<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Support\Sharing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * A collection another household shared with one of my groups: its shared entries with their
 * values, adding entries and ticking them off. Private entries and links into the other household
 * (people, relations) stay out.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /**
     * Property types shown here; people and relations point into the other household.
     */
    private const SHOWN_TYPES = [
        CollectionProperty::TYPE_TEXT, CollectionProperty::TYPE_NUMBER, CollectionProperty::TYPE_DATE, CollectionProperty::TYPE_SELECT,
        CollectionProperty::TYPE_MULTI_SELECT, CollectionProperty::TYPE_CHECKBOX, CollectionProperty::TYPE_URL,
    ];

    #[Locked]
    public int $collectionId;

    public string $title = '';

    public function mount(Collection $collection): void
    {
        $this->resolveHousehold();
        $found = $this->collection($collection->id);

        if ($found->household_id === $this->householdId) {
            abort(new RedirectResponse(route('collections.show', $found)));
        }

        $this->collectionId = $found->id;
    }

    private function collection(?int $id = null): Collection
    {
        $collection = Sharing::find(Sharing::COLLECTION, $id ?? $this->collectionId, Auth::user(), (int) $this->householdId);

        return $collection instanceof Collection ? $collection : abort(404);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Item>
     */
    private function entries()
    {
        $collection = $this->collection();

        return Item::query()->where('collection_id', $collection->id)->where('household_id', $collection->household_id)
            ->where('visibility', Item::VISIBILITY_SHARED);
    }

    public function add(): void
    {
        $data = $this->validate(['title' => ['required', 'string', 'max:255']], attributes: ['title' => __('Title')]);
        $collection = $this->collection();
        Item::create([
            'household_id' => $collection->household_id,
            'collection_id' => $collection->id,
            'type' => Item::TYPE_ENTRY,
            'visibility' => Item::VISIBILITY_SHARED,
            'title' => $data['title'],
            'content' => ['body' => ''],
            'created_by' => Auth::id(),
        ]);
        $this->reset('title');
    }

    public function toggleDone(int $id): void
    {
        $entry = $this->entries()->findOrFail($id);
        $entry->update(['done' => ! $entry->done, 'completed_at' => $entry->done ? null : now()]);
    }

    protected function display(CollectionProperty $property, mixed $value): string
    {
        return match ($property->type) {
            CollectionProperty::TYPE_CHECKBOX => $value ? '✓' : '',
            CollectionProperty::TYPE_DATE => $value ? rescue(fn () => \Illuminate\Support\Carbon::parse((string) $value)->format('d.m.Y'), '', false) : '',
            CollectionProperty::TYPE_SELECT => (string) ($property->option(is_string($value) ? $value : null)['name'] ?? ''),
            CollectionProperty::TYPE_MULTI_SELECT => collect(is_array($value) ? $value : [])->map(fn ($id) => $property->option((string) $id)['name'] ?? null)->filter()->implode(', '),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    public function with(): array
    {
        $collection = $this->collection()->load('household', 'properties');

        return [
            'collection' => $collection,
            'properties' => $collection->properties->filter(fn (CollectionProperty $property) => in_array($property->type, self::SHOWN_TYPES, true))->take(4)->values(),
            'entries' => $this->entries()->orderBy('done')->latest('id')->limit(500)->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('groups.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('Groups & sharing') }}</a>
            <h2 class="w-full font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $collection->icon ?: '🗂️' }} {{ $collection->name }} <span class="text-sm font-normal text-gray-500">· {{ $collection->household?->name }}</span></h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6 lg:px-8">
            <livewire:sharing.panel type="collection" :id="$collection->id" :wire:key="'share-collection-'.$collection->id" />

            <section class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                <form wire:submit="add" class="flex gap-2">
                    <x-text-input wire:model="title" class="block w-full" type="text" placeholder="{{ __('New entry') }}" aria-label="{{ __('Title') }}" />
                    <x-primary-button type="submit">{{ __('Add') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('title')" class="mt-1" />

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="w-8 py-2"><span class="sr-only">{{ __('Done') }}</span></th>
                                <th class="py-2 pe-3">{{ __('Title') }}</th>
                                @foreach ($properties as $property)
                                    <th class="py-2 pe-3">{{ $property->name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($entries as $entry)
                                <tr wire:key="entry-{{ $entry->id }}">
                                    <td class="py-2"><input type="checkbox" wire:click="toggleDone({{ $entry->id }})" @checked($entry->done) class="rounded-sm border-gray-300 text-indigo-600" aria-label="{{ __('Done') }}"></td>
                                    <td @class(['py-2 pe-3', 'text-gray-400 line-through' => $entry->done, 'text-gray-900 dark:text-gray-100' => ! $entry->done])>{{ $entry->title }}</td>
                                    @foreach ($properties as $property)
                                        <td class="py-2 pe-3 text-gray-600 dark:text-gray-300">{{ $this->display($property, $entry->propertyValue($property)) }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ $properties->count() + 2 }}" class="py-3 text-gray-500 dark:text-gray-400">{{ __('No shared entries yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>
