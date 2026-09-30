<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\Item;
use App\Models\Space;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Spaces (workspaces) like "Work", "Home" or "Club" that group pages and tasks.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array{name: string, icon: string, private: bool}
     */
    public array $form = ['name' => '', 'icon' => '🗂️', 'private' => false];

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->showForm = request()->query('create') === '1';
    }

    private function findSpace(int $id): Space
    {
        return Space::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($id) ?? abort(404);
    }

    public function create(): void
    {
        $this->reset(['editingId', 'form']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $space = $this->findSpace($id);
        $this->editingId = $space->id;
        $this->form = ['name' => $space->name, 'icon' => $space->icon, 'private' => $space->isPrivate()];
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:60'],
            'form.icon' => ['required', Rule::in(Space::ICONS)],
        ], attributes: ['form.name' => __('Name')])['form'];

        $attributes = [
            'name' => $data['name'],
            'icon' => $data['icon'],
            'visibility' => $this->form['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
        ];

        if ($this->editingId) {
            $space = $this->findSpace($this->editingId);
            // Only the creator can make a shared space private (it would vanish for the others).
            $space->update($space->created_by === Auth::id() ? $attributes : ['name' => $data['name'], 'icon' => $data['icon']]);
        } else {
            $space = Space::create($attributes + [
                'household_id' => $this->householdId,
                'created_by' => Auth::id(),
                'position' => (int) Space::where('household_id', $this->householdId)->max('position') + 1,
            ]);
        }

        $this->redirectRoute('spaces.show', ['space' => $space->id], navigate: true);
    }

    /**
     * Pages and tasks stay; they only leave the space.
     */
    public function delete(int $id): void
    {
        $this->findSpace($id)->delete();
        $this->reset(['editingId', 'form', 'showForm']);
    }

    public function with(): array
    {
        $spaces = Space::query()->visibleTo(Auth::user(), (int) $this->householdId)->get();
        $tasksCollectionId = Collection::tasksFor((int) $this->householdId)->id;
        $visible = fn () => Item::query()->forHousehold((int) $this->householdId)->visibleTo(Auth::user())->whereIn('space_id', $spaces->pluck('id'));

        return [
            'spaces' => $spaces,
            'pageCounts' => $visible()->where('type', Item::TYPE_NOTE)->selectRaw('space_id, count(*) as aggregate')->groupBy('space_id')->pluck('aggregate', 'space_id'),
            'taskCounts' => $visible()->where('collection_id', $tasksCollectionId)->where('done', false)->selectRaw('space_id, count(*) as aggregate')->groupBy('space_id')->pluck('aggregate', 'space_id'),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🗂️ {{ __('Spaces') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('A space groups pages and tasks that belong together, e.g. Work, Home or Club.') }}</p>
                <x-primary-button type="button" wire:click="create" class="shrink-0">+ {{ __('New space') }}</x-primary-button>
            </div>

            @if ($showForm)
                <form wire:submit="save" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="form.name" class="{{ $input }}" placeholder="{{ __('e.g. Work, Home, Club') }}" maxlength="60" autofocus><x-input-error :messages="$errors->get('form.name')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Symbol') }}
                        <select wire:model="form.icon" class="{{ $input }}">
                            @foreach (\App\Models\Space::ICONS as $icon)
                                <option value="{{ $icon }}">{{ $icon }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-6 dark:text-gray-300"><input type="checkbox" wire:model="form.private" class="rounded border-gray-300 text-indigo-600"> {{ __('Only visible to me') }}</label>
                    <div class="flex flex-wrap gap-2 sm:col-span-6">
                        <x-primary-button>{{ $editingId ? __('Save changes') : __('Create') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                        @if ($editingId)
                            <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this space? Its pages and tasks are kept.') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                        @endif
                    </div>
                </form>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                @forelse ($spaces as $space)
                    <div wire:key="space-{{ $space->id }}" class="flex items-start gap-3 rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <a href="{{ route('spaces.show', $space) }}" wire:navigate class="min-w-0 flex-1">
                            <p class="text-lg font-semibold text-gray-900 hover:underline dark:text-gray-100">{{ $space->icon }} {{ $space->name }}
                                @if ($space->isPrivate())
                                    <x-private-badge />
                                @endif
                            </p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __(':pages pages · :tasks open tasks', ['pages' => $pageCounts[$space->id] ?? 0, 'tasks' => $taskCounts[$space->id] ?? 0]) }}</p>
                        </a>
                        <button type="button" wire:click="edit({{ $space->id }})" class="text-sm text-gray-500 underline dark:text-gray-400">{{ __('Edit') }}</button>
                    </div>
                @empty
                    <section class="rounded-2xl bg-white p-6 text-center shadow-sm sm:col-span-2 dark:bg-gray-800">
                        <p class="text-4xl" aria-hidden="true">🗂️</p>
                        <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No space yet.') }}</p>
                    </section>
                @endforelse
            </div>
        </div>
    </div>
</div>
