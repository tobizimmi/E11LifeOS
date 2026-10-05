<?php

use App\Models\SupportTicket;
use App\Support\Helpdesk\Macros;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Admin › Support › Canned responses: answers the team uses again and again, with placeholders
 * and the status a ticket gets when the answer is sent.
 */
new #[Layout('layouts.app')] class extends Component
{
    public ?string $editingId = null;

    public bool $editing = false;

    public string $title = '';

    public string $body = '';

    public string $status = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function create(): void
    {
        $this->authorize('admin');
        $this->reset('editingId', 'title', 'body', 'status');
        $this->resetValidation();
        $this->editing = true;
    }

    public function edit(string $id): void
    {
        $this->authorize('admin');
        $macro = Macros::find($id);
        abort_if($macro === null, 404);
        $this->fill(['editingId' => $id, 'title' => $macro['title'], 'body' => $macro['body'], 'status' => (string) $macro['status'], 'editing' => true]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize('admin');
        $this->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(['open', 'pending', 'on_hold', 'solved'])],
        ], attributes: ['title' => __('Title'), 'body' => __('Text')]);

        Macros::save($this->editingId, trim($this->title), trim($this->body), $this->status !== '' ? $this->status : null);
        $this->reset('editingId', 'title', 'body', 'status', 'editing');
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function delete(string $id): void
    {
        $this->authorize('admin');
        Macros::delete($id);
        $this->reset('editingId', 'editing');
    }

    public function with(): array
    {
        return [
            'macros' => Macros::all(),
            'statusLabels' => SupportTicket::statusLabels(),
        ];
    }
}; ?>

@php($input = 'block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200')

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Administration') }}</h2>
        <x-admin-nav />
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <a href="{{ route('admin.support') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400">‹ {{ __('Support') }}</a>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">💬 {{ __('Canned responses') }}</h3>
                </div>
                <x-primary-button type="button" wire:click="create">+ {{ __('Canned response') }}</x-primary-button>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Placeholders: :list – they are replaced with the first name of the requester, the ticket number, the subject and your first name.', ['list' => implode(' ', Macros::PLACEHOLDERS)]) }}</p>

            @if ($editing)
                <form wire:submit="save" class="space-y-3 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Title') }}
                        <input type="text" wire:model="title" class="mt-1 {{ $input }}" placeholder="{{ __('e.g. Clear browser cache') }}">
                    </label>
                    <x-input-error :messages="$errors->get('title')" />
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Text') }}
                        <textarea wire:model="body" rows="6" class="mt-1 {{ $input }}" placeholder="{{ __('Hello {name}, …') }}"></textarea>
                    </label>
                    <x-input-error :messages="$errors->get('body')" />
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Then set the status to') }}
                        <select wire:model="status" class="mt-1 {{ $input }}">
                            <option value="">– {{ __('choose when sending') }} –</option>
                            @foreach (['open', 'pending', 'on_hold', 'solved'] as $key)<option value="{{ $key }}">{{ $statusLabels[$key] }}</option>@endforeach
                        </select>
                    </label>
                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" wire:click="$set('editing', false)">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                    </div>
                </form>
            @endif

            <ul class="divide-y divide-gray-100 rounded-2xl bg-white shadow-xs dark:divide-gray-700 dark:bg-gray-800">
                @forelse ($macros as $macro)
                    <li wire:key="macro-{{ $macro['id'] }}" class="flex items-start gap-3 px-4 py-3">
                        <button type="button" wire:click="edit('{{ $macro['id'] }}')" class="min-w-0 flex-1 text-start">
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $macro['title'] }}</span>
                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($macro['body'], 120) }}@if ($macro['status']) · → {{ $statusLabels[$macro['status']] }}@endif</span>
                        </button>
                        <button type="button" wire:click="delete('{{ $macro['id'] }}')" wire:confirm="{{ __('Delete this canned response?') }}" class="text-xs text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No canned responses yet.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
