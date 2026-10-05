{{-- Quick entry of a roadmap item (bug, feature or item) into a milestone or the backlog. --}}
<form wire:submit="addItem('{{ $target }}')" class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-5 py-3 dark:border-gray-700">
    <select wire:model="newItem.{{ $target }}.type" class="rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Type') }}">
        <option value="task">📌 {{ $types['task']['label'] }}</option>
        <option value="feature">💡 {{ $types['feature']['label'] }}</option>
        <option value="bug">🐞 {{ $types['bug']['label'] }}</option>
    </select>
    <input type="text" wire:model="newItem.{{ $target }}.title" maxlength="150" placeholder="{{ __('New item …') }}" aria-label="{{ __('Title') }}" class="min-w-0 flex-1 rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
    <select wire:model="newItem.{{ $target }}.priority" class="rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Priority') }}">
        @foreach ($priorityLabels as $key => $label)<option value="{{ $key }}" @selected($key === 'normal')>{{ $label }}</option>@endforeach
    </select>
    <x-secondary-button type="submit">+ {{ __('Add') }}</x-secondary-button>
    <x-input-error :messages="$errors->get('newItem.'.$target.'.title')" class="w-full" />
</form>
