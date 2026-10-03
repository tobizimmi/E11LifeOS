{{-- One entry of the trip plan (idea or planned): drag handle, time, type, place, cost, done. --}}
<li wire:key="stop-{{ $entry->id }}" x-sort:item="{{ $entry->id }}" class="group flex flex-wrap items-stretch gap-x-2 rounded-xl bg-white p-2.5 ring-1 ring-gray-100 dark:bg-gray-900/40 dark:ring-gray-700">
    @if ($leg ?? null)
        {{-- The way here: tap to switch between on foot, by bike and by car. --}}
        <button type="button" wire:click="cycleTravelMode({{ $entry->id }})" class="mb-1 basis-full text-left text-[11px] font-medium text-indigo-600 hover:underline dark:text-indigo-300" title="{{ __('Tap to change: on foot, by bike, by car') }}">↓ {{ $leg }} <span class="text-gray-400" aria-hidden="true">⇄</span></button>
    @endif
    <span x-sort:handle class="flex cursor-grab items-center px-0.5 text-gray-300 hover:text-gray-500 dark:text-gray-600" title="{{ __('Drag to move') }}" aria-hidden="true">⠿</span>
    @if ($entry->time)
        <span class="w-11 shrink-0 pt-1 text-center text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-300">{{ $entry->time }}@if ($entry->end_time)<span class="block font-normal text-gray-400">{{ $entry->end_time }}</span>@endif</span>
    @endif
    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-lg {{ $stopKinds[$entry->kind]['color'] ?? 'bg-gray-100' }}" aria-hidden="true">{{ $stopKinds[$entry->kind]['icon'] ?? '📍' }}</span>
    <button type="button" wire:click="editStop({{ $entry->id }})" class="min-w-0 flex-1 text-left">
        <span @class(['line-clamp-2 block font-medium leading-snug', 'text-gray-400 line-through dark:text-gray-500' => $entry->done, 'text-gray-900 dark:text-gray-100' => ! $entry->done])>{{ $entry->title }}</span>
        @if ($entry->location || $entry->cost !== null)
            <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ collect([$entry->location ? '📍 '.$entry->location : null, $entry->cost !== null ? $euro($entry->cost) : null])->filter()->implode(' · ') }}</span>
        @endif
        @if ($entry->notes)
            <span class="block truncate text-xs text-gray-400 dark:text-gray-500">{{ $entry->notes }}</span>
        @endif
    </button>
    @if ($entry->mapsUrl())
        <a href="{{ $entry->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="flex items-center rounded-lg px-1.5 text-gray-400 hover:bg-gray-100 hover:text-indigo-600 dark:hover:bg-gray-700" title="{{ __('Show on map') }}" aria-label="{{ __('Show :title on map', ['title' => $entry->title]) }}">🗺️</a>
    @endif
    <label class="flex items-center px-1" title="{{ $entry->day ? __('Done') : __('Visited') }}"><input type="checkbox" @checked($entry->done) wire:click="toggleStopDone({{ $entry->id }})" class="h-5 w-5 rounded-full border-gray-300 text-emerald-600 dark:border-gray-600" aria-label="{{ __('Mark :title as done', ['title' => $entry->title]) }}"></label>
</li>
