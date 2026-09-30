<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\CollectionTemplate;
use App\Models\Item;
use App\Support\CollectionTemplates;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public bool $showForm = false;

    public string $name = '';

    public string $icon = '';

    public string $template = 'blank';

    public bool $isPrivate = false;

    /**
     * ?create=1 (sidebar "+") opens the form right away.
     */
    public function mount(): void
    {
        if (request()->query('create') === '1') {
            $this->showForm = true;
        }
    }

    /**
     * Templates meant for personal notes (journal) start private.
     */
    public function updatedTemplate(string $template): void
    {
        $this->isPrivate = (bool) (CollectionTemplates::all()[$template]['private'] ?? false);

        if (str_starts_with($template, 'custom:') && $this->name === '') {
            $this->name = (string) CollectionTemplate::where('household_id', $this->householdId)->whereKey((int) substr($template, 7))->value('name');
        }
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:16'],
            'template' => ['required', 'string'],
        ]);

        // Own templates ("custom:ID") of this household.
        if (str_starts_with($validated['template'], 'custom:')) {
            $template = CollectionTemplate::where('household_id', $this->householdId)->find((int) substr($validated['template'], 7)) ?? abort(404);
            $collection = $template->createCollection((int) $this->householdId, Auth::user(), trim($validated['name']), $this->isPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED);

            if (trim((string) ($validated['icon'] ?? '')) !== '') {
                $collection->update(['icon' => trim($validated['icon'])]);
            }

            $this->redirectRoute('collections.show', ['collection' => $collection->id], navigate: true);

            return;
        }

        $this->validate(['template' => [Rule::in(array_keys(CollectionTemplates::all()))]]);

        $collection = Collection::create([
            'household_id' => $this->householdId,
            'name' => trim($validated['name']),
            'icon' => trim((string) ($validated['icon'] ?? '')) ?: CollectionTemplates::all()[$validated['template']]['icon'],
            'visibility' => $this->isPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'created_by' => Auth::id(),
        ]);

        CollectionTemplates::apply($collection, $validated['template']);

        $this->redirectRoute('collections.show', ['collection' => $collection->id], navigate: true);
    }

    public function deleteTemplate(int $templateId): void
    {
        (CollectionTemplate::where('household_id', $this->householdId)->find($templateId) ?? abort(404))->delete();

        if ($this->template === 'custom:'.$templateId) {
            $this->template = 'blank';
        }
    }

    public function with(): array
    {
        // Make sure the built-in tasks collection exists for this household.
        Collection::tasksFor($this->householdId);

        return [
            'collections' => Collection::query()
                ->visibleTo(Auth::user(), $this->householdId)
                ->withCount(['items' => fn ($items) => $items->forHousehold($this->householdId)->visibleTo(Auth::user())])
                ->orderByRaw('system_key IS NULL')
                ->orderBy('name')
                ->get(),
            'templates' => CollectionTemplates::all(),
            'ownTemplates' => CollectionTemplate::where('household_id', $this->householdId)->orderBy('name')->get(),
        ];
    }
}; ?>

<div x-on:page-primary.window="$wire.set('showForm', true)">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Collections') }}</h2>
            <x-primary-button type="button" x-on:click="$dispatch('page-primary')" class="shrink-0">+ {{ __('New collection') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Collections are lists with your own properties – as a table or a board.') }}</p>

            @if ($showForm)
                <form wire:submit="create" class="bg-white dark:bg-gray-800 shadow-sm rounded-2xl p-6 space-y-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('New collection') }}</h3>

                    <div class="flex gap-3">
                        <div class="w-20 shrink-0">
                            <x-input-label for="icon" :value="__('Icon')" />
                            <x-text-input wire:model="icon" id="icon" class="mt-1 block w-full text-center text-xl" maxlength="16" placeholder="{{ $templates[$template]['icon'] ?? ($ownTemplates->firstWhere('id', (int) str_replace('custom:', '', $template))?->icon ?: '🗂️') }}" />
                        </div>
                        <div class="flex-1">
                            <x-input-label for="name" :value="__('Name')" />
                            <x-text-input wire:model="name" id="name" class="mt-1 block w-full" required autofocus placeholder="{{ __('e.g. Contracts, Projects, Books') }}" />
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('name')" />

                    <fieldset>
                        <legend class="block font-medium text-sm text-gray-700 dark:text-gray-300">{{ __('Template') }}</legend>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            @foreach ($templates as $key => $templateInfo)
                                <label wire:key="template-{{ $key }}" @class([
                                    'flex cursor-pointer gap-3 rounded-lg border p-3',
                                    'border-indigo-500 ring-1 ring-indigo-500 bg-indigo-50/50 dark:bg-gray-700' => $template === $key,
                                    'border-gray-200 dark:border-gray-700' => $template !== $key,
                                ])>
                                    <input type="radio" wire:model.live="template" value="{{ $key }}" class="mt-1 text-indigo-600" />
                                    <span>
                                        <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $templateInfo['icon'] }} {{ $templateInfo['label'] }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $templateInfo['description'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @if ($ownTemplates->isNotEmpty())
                            <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Your templates') }}</p>
                            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                @foreach ($ownTemplates as $ownTemplate)
                                    <label wire:key="own-template-{{ $ownTemplate->id }}" @class([
                                        'group flex cursor-pointer gap-3 rounded-lg border p-3',
                                        'border-indigo-500 ring-1 ring-indigo-500 bg-indigo-50/50 dark:bg-gray-700' => $template === 'custom:'.$ownTemplate->id,
                                        'border-gray-200 dark:border-gray-700' => $template !== 'custom:'.$ownTemplate->id,
                                    ])>
                                        <input type="radio" wire:model.live="template" value="custom:{{ $ownTemplate->id }}" class="mt-1 text-indigo-600" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $ownTemplate->icon ?: '🗂️' }} {{ $ownTemplate->name }}</span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $ownTemplate->description ?: trans_choice(':count field|:count fields', count($ownTemplate->definition['properties'] ?? [])) }}</span>
                                        </span>
                                        <button type="button" wire:click.prevent="deleteTemplate({{ $ownTemplate->id }})" wire:confirm="{{ __('Delete this template? Collections created from it stay.') }}" class="self-start px-1 text-xs text-gray-400 hover:text-red-600" aria-label="{{ __('Delete template') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </fieldset>

                    <x-private-checkbox id="isPrivate" wire:model="isPrivate" />

                    <div class="flex items-center gap-3">
                        <x-primary-button>{{ __('Create collection') }}</x-primary-button>
                        <button type="button" wire:click="$set('showForm', false)" class="text-sm text-gray-500 dark:text-gray-400 underline">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif

            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($collections as $collection)
                    <li wire:key="collection-{{ $collection->id }}">
                        <a href="{{ route('collections.show', $collection) }}" wire:navigate class="block rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm hover:ring-2 hover:ring-indigo-300">
                            <div class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                <span aria-hidden="true">{{ $collection->displayIcon() }}</span>
                                <span class="truncate">{{ $collection->name }}</span>
                                @if ($collection->isPrivate())
                                    <x-private-badge />
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ trans_choice(':count entry|:count entries', $collection->items_count) }}
                                @if ($collection->isTasks())
                                    · {{ __('built-in') }}
                                @endif
                            </p>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
