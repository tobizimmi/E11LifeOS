<?php

use App\Models\SupportTicket;
use App\Support\Helpdesk\Tickets;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Help & support: write a request to the team and follow the own requests.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    #[Url(as: 'new')]
    public bool $writing = false;

    /** Page the member came from (sent along to help with the problem). */
    #[Url(as: 'from')]
    public string $fromUrl = '';

    public string $type = 'question';

    public string $subject = '';

    public string $body = '';

    public string $area = '';

    public string $priority = 'normal';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    public string $agent = '';

    public function mount(): void
    {
        $this->agent = mb_substr((string) request()->userAgent(), 0, 300);
        $this->writing = $this->writing || ! SupportTicket::where('user_id', Auth::id())->exists();
    }

    public function submit(): void
    {
        $this->validate([
            'type' => ['required', Rule::in(array_keys(SupportTicket::requesterTypeLabels()))],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:10000'],
            'area' => ['nullable', Rule::in(array_keys(ModuleCatalog::all()))],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
            'files' => ['array', 'max:3'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf,txt,log'],
        ], attributes: ['subject' => __('Subject'), 'body' => __('Description'), 'files.*' => __('Attachment')]);

        $key = 'support-ticket:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['subject' => __('Too many requests – please try again later.')]);
        }

        RateLimiter::hit($key, 3600);

        $ticket = Tickets::open(Auth::user(), [
            'subject' => $this->subject,
            'body' => $this->body,
            'type' => $this->type,
            'priority' => $this->priority,
            'area' => $this->area ?: null,
            'url' => $this->fromUrl ?: null,
            'agent' => $this->agent,
        ], array_values($this->files));

        $this->redirectRoute('support.show', $ticket, navigate: true);
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function with(): array
    {
        $user = Auth::user();

        return [
            'tickets' => SupportTicket::where('user_id', $user->id)->latest('last_activity_at')->get(),
            'types' => SupportTicket::requesterTypeLabels(),
            'areas' => collect(ModuleCatalog::all())->filter(fn (array $module, string $key) => ModuleAccess::allows($user, $key))->all(),
        ];
    }
}; ?>

@php
    $input = 'block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Help & support') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if ($writing)
                <form wire:submit="submit" class="space-y-4 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('New request') }}</h3>
                        @if ($tickets->isNotEmpty())
                            <button type="button" wire:click="$set('writing', false)" class="text-sm text-gray-500 underline dark:text-gray-400">{{ __('Cancel') }}</button>
                        @endif
                    </div>

                    <fieldset>
                        <legend class="text-sm text-gray-700 dark:text-gray-300">{{ __('What is it about?') }}</legend>
                        <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-5">
                            @foreach ($types as $key => $typeOption)
                                <label wire:key="type-{{ $key }}" @class(['flex cursor-pointer flex-col items-center gap-1 rounded-xl p-3 text-center text-xs ring-1 transition', 'bg-indigo-50 text-indigo-800 ring-indigo-300 dark:bg-indigo-900/40 dark:text-indigo-100 dark:ring-indigo-700' => $type === $key, 'text-gray-700 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-700/40' => $type !== $key])>
                                    <input type="radio" wire:model.live="type" value="{{ $key }}" class="sr-only">
                                    <span class="text-xl" aria-hidden="true">{{ $typeOption['icon'] }}</span>{{ $typeOption['label'] }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Subject') }}
                        <input type="text" wire:model="subject" maxlength="150" class="mt-1 {{ $input }}" placeholder="{{ $type === 'bug' ? __('e.g. Saving a trip shows an error') : ($type === 'feature' ? __('e.g. Export the shopping list as PDF') : __('Short summary')) }}">
                        <x-input-error :messages="$errors->get('subject')" />
                    </label>

                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Description') }}
                        <textarea wire:model="body" rows="6" class="mt-1 {{ $input }}" placeholder="{{ $type === 'bug' ? __('What did you do, what happened and what did you expect?') : __('Describe your request as precisely as possible.') }}"></textarea>
                        <x-input-error :messages="$errors->get('body')" />
                    </label>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Area (optional)') }}
                            <select wire:model="area" class="mt-1 {{ $input }}">
                                <option value="">{{ __('General / not sure') }}</option>
                                @foreach ($areas as $key => $module)
                                    <option value="{{ $key }}">{{ $module['icon'] }} {{ $module['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('How urgent?') }}
                            <select wire:model="priority" class="mt-1 {{ $input }}">
                                <option value="low">{{ __('Not urgent') }}</option>
                                <option value="normal">{{ __('Normal') }}</option>
                                <option value="high">{{ __('Urgent – I cannot keep working') }}</option>
                            </select>
                        </label>
                    </div>

                    <div>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Screenshots or files (up to 3, max. 10 MB each)') }}
                            <input type="file" wire:model="files" multiple accept="image/*,.pdf,.txt,.log" class="mt-1 block w-full text-sm text-gray-600 file:me-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
                        </label>
                        <div wire:loading wire:target="files" class="mt-1 text-xs text-gray-500">{{ __('Uploading …') }}</div>
                        @if ($files)
                            <ul class="mt-2 flex flex-wrap gap-2">
                                @foreach ($files as $index => $file)
                                    <li class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200" wire:key="file-{{ $index }}">📎 {{ $file->getClientOriginalName() }} <button type="button" wire:click="removeFile({{ $index }})" class="text-gray-500" aria-label="{{ __('Remove') }}">✕</button></li>
                                @endforeach
                            </ul>
                        @endif
                        <x-input-error :messages="$errors->get('files')" /><x-input-error :messages="$errors->get('files.*')" />
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only the administrators of this LifeOS read your request. The page you came from, the app version and your browser are sent along to help with the problem – never the content of your household.') }}</p>
                    <x-primary-button wire:loading.attr="disabled" wire:target="submit,files">{{ __('Send request') }}</x-primary-button>
                </form>
            @else
                <div class="flex justify-end">
                    <x-primary-button type="button" wire:click="$set('writing', true)">+ {{ __('New request') }}</x-primary-button>
                </div>
            @endif

            @if ($tickets->isNotEmpty())
                <section class="rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                    <h3 class="px-5 pt-5 font-semibold text-gray-900 dark:text-gray-100">{{ __('My requests') }}</h3>
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($tickets as $ticket)
                            <li wire:key="ticket-{{ $ticket->id }}">
                                <a href="{{ route('support.show', $ticket) }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-gray-900 dark:text-gray-100">@if ($ticket->requester_unread)<span class="me-1 inline-block h-2 w-2 rounded-full bg-indigo-500" aria-label="{{ __('New answer') }}"></span>@endif{{ $types[$ticket->type]['icon'] ?? '' }} {{ $ticket->subject }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $ticket->number() }} · {{ $ticket->last_activity_at?->diffForHumans() }}</span>
                                    </span>
                                    <span @class(['shrink-0 rounded-full px-2.5 py-0.5 text-xs', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $ticket->status === 'pending', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => in_array($ticket->status, ['solved', 'closed'], true), 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200' => in_array($ticket->status, ['new', 'open', 'on_hold'], true)])>{{ $ticket->requesterStatus() }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
