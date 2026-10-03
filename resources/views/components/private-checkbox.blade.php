@props(['id'])

<label for="{{ $id }}" class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
    <input
        id="{{ $id }}"
        type="checkbox"
        {{ $attributes->merge(['class' => 'rounded-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-xs focus:ring-indigo-500']) }}
    />
    {{ __('Private (only visible to me)') }}
</label>
