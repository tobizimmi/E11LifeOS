@props(['entry', 'collection', 'groupProperty' => null])

{{-- One entry in the calendar: draggable, opens the side panel on click. --}}
@php
    $option = $groupProperty ? $groupProperty->option($entry->propertyValue($groupProperty)) : null;
    $isDone = $collection->isTasks() && $entry->done;
@endphp

<li x-sort:item="{{ $entry->id }}" wire:key="chip-{{ $entry->id }}" class="cursor-grab">
    <button
        type="button"
        wire:click="openEntry({{ $entry->id }})"
        title="{{ $entry->title }}"
        @class([
            'flex w-full items-center gap-1 truncate rounded-sm px-1.5 py-0.5 text-start text-xs',
            'bg-indigo-50 text-indigo-900 hover:bg-indigo-100 dark:bg-indigo-900/40 dark:text-indigo-100' => ! $isDone,
            'bg-gray-100 text-gray-400 line-through dark:bg-gray-700' => $isDone,
        ])
    >
        @if ($option)
            {{-- Narrow day cells on phones only show the title. --}}
            <x-option-badge :option="$option" class="hidden sm:inline-flex !px-1 !py-0 text-[10px]" />
        @endif
        <span class="truncate">{{ $entry->title }}</span>
    </button>
</li>
