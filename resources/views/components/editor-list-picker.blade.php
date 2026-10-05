{{-- "/ Insert list" in the page editor: packing lists, checklists and shopping lists (inside x-data="pageEditor"). --}}
<template x-teleport="body">
<div x-cloak x-show="listPicker.open" x-transition.opacity class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/40 p-0 sm:items-center sm:p-4" x-on:keydown.escape.window="listPicker.open = false" x-on:click.self="listPicker.open = false">
    <div class="flex max-h-[80vh] w-full max-w-md flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl dark:bg-gray-800" role="dialog" aria-modal="true" aria-label="{{ __('Insert list') }}" x-trap.noscroll="listPicker.open">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
            <p class="font-semibold text-gray-900 dark:text-gray-100">📋 {{ __('Insert list') }}</p>
            <button type="button" x-on:click="listPicker.open = false" class="text-sm text-gray-500 dark:text-gray-400">{{ __('Cancel') }}</button>
        </div>
        <div class="px-4 pt-3">
            <input type="search" x-model="listPicker.filter" placeholder="{{ __('Search lists') }}" aria-label="{{ __('Search lists') }}" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        </div>
        <div class="overflow-y-auto px-2 pb-3 pt-1">
            <p x-show="listPicker.loading" class="px-2 py-3 text-sm text-gray-500">{{ __('Loading …') }}</p>
            <template x-for="[group, options] in listGroups()" :key="group">
                <section class="mt-2">
                    <p class="px-2 text-xs font-semibold uppercase tracking-wide text-gray-400" x-text="group"></p>
                    <template x-for="option in options" :key="option.key">
                        <div class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <button type="button" x-on:click="insertList(option.key)" class="flex min-w-0 flex-1 items-center gap-2 text-start text-sm text-gray-800 dark:text-gray-100">
                                <span aria-hidden="true" x-text="option.icon"></span>
                                <span class="truncate" x-text="option.name"></span>
                                <span class="shrink-0 text-xs text-gray-400" x-text="option.count"></span>
                            </button>
                            <template x-if="option.live">
                                <button type="button" x-on:click="insertList(option.key, true)" class="shrink-0 rounded-md px-2 py-0.5 text-xs text-indigo-600 ring-1 ring-indigo-200 dark:text-indigo-300 dark:ring-indigo-800" title="{{ __('Always shows the current entries of the list') }}">{{ __('live') }}</button>
                            </template>
                        </div>
                    </template>
                </section>
            </template>
            <p class="px-2 pt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Lists are inserted as a checklist you can tick off. Shopping lists can also be shown live.') }}</p>
        </div>
    </div>
</div>
</template>
