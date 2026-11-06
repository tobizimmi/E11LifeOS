{{-- One table row for a collection entry. Expects: $entry, $collection, $visibleProperties, $members, $relationNodes, $openEntry. --}}
<tr wire:key="row-{{ $entry->id }}" @class(['hover:bg-gray-50 dark:hover:bg-gray-700/40', 'bg-indigo-50/60 dark:bg-gray-700/60' => $openEntry?->id === $entry->id])>
    @if ($collection->isTasks())
        <td class="px-3 py-1.5">
            <input type="checkbox" wire:key="done-{{ $entry->id }}-{{ $entry->updated_at?->timestamp }}-{{ (int) $entry->done }}" wire:click="toggleDone({{ $entry->id }})" @checked($entry->done) aria-label="{{ __('Mark as done') }}" class="rounded-sm border-gray-300 dark:border-gray-600 text-indigo-600" />
        </td>
    @endif
    <td class="px-3 py-1.5">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="openEntry({{ $entry->id }})" @class(['text-start font-medium hover:underline', 'line-through text-gray-400' => $collection->isTasks() && $entry->done, 'text-gray-900 dark:text-gray-100' => ! ($collection->isTasks() && $entry->done)])>
                {{ $entry->title }}
            </button>
            @foreach ($entry->tags->sortBy('name') as $entryTag)
                <span class="text-xs text-indigo-600 dark:text-indigo-300">#{{ $entryTag->name }}</span>
            @endforeach
            @if ($entry->isPrivate() && ! $collection->isPrivate())
                <x-private-badge />
            @endif
            @if ($entry->body() !== '')
                <span class="text-gray-400" title="{{ __('Has notes') }}" aria-hidden="true">≡</span>
            @endif
        </div>
    </td>
    @foreach ($visibleProperties as $property)
        <td class="px-1 py-1" wire:key="cell-{{ $entry->id }}-{{ $property->id }}">
            <x-property-input :property="$property" :item="$entry" :members="$members" :nodes="$relationNodes" compact />
        </td>
    @endforeach
    <td class="px-3 py-1.5 text-end">
        <button type="button" wire:click="deleteEntry({{ $entry->id }})" wire:confirm="{{ __('Delete this entry?') }}" class="text-gray-400 hover:text-red-600" title="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
    </td>
</tr>
