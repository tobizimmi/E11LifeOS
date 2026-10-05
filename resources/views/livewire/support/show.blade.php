<?php

use App\Models\SupportTicket;
use App\Support\Helpdesk\Tickets;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * One request of the member: the conversation with the support, answering, marking it solved
 * and rating the help.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $ticketId;

    public string $body = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    public string $ratingComment = '';

    public function mount(SupportTicket $ticket): void
    {
        abort_unless($ticket->user_id === Auth::id(), 404);
        $this->ticketId = $ticket->id;

        if ($ticket->requester_unread) {
            $ticket->forceFill(['requester_unread' => false])->save();
        }
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::where('user_id', Auth::id())->findOrFail($this->ticketId);
    }

    public function send(): void
    {
        $this->validate([
            'body' => ['required', 'string', 'max:10000'],
            'files' => ['array', 'max:3'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf,txt,log'],
        ], attributes: ['body' => __('Answer'), 'files.*' => __('Attachment')]);

        Tickets::reply($this->ticket(), Auth::user(), $this->body, array_values($this->files));
        $this->reset('body', 'files');
        $this->dispatch('toast', message: __('Sent.'));
    }

    public function markSolved(): void
    {
        $ticket = $this->ticket();
        abort_unless(in_array($ticket->status, SupportTicket::UNSOLVED, true), 422);
        Tickets::change($ticket, Auth::user(), ['status' => 'solved'], notify: false);
    }

    public function rate(string $rating): void
    {
        $this->validate(['ratingComment' => ['nullable', 'string', 'max:1000']]);
        Tickets::rate($this->ticket(), Auth::user(), $rating, $this->ratingComment ?: null);
        $this->dispatch('toast', message: __('Thank you for your feedback!'));
    }

    public function with(): array
    {
        $ticket = $this->ticket();

        return [
            'ticket' => $ticket,
            'messages' => $ticket->messages()->with('author:id,name')->get(),
            'types' => SupportTicket::typeLabels(),
        ];
    }
}; ?>

@php
    $input = 'block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <a href="{{ route('support.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400">‹ {{ __('Help & support') }}</a>
        <h2 class="mt-1 font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $types[$ticket->type]['icon'] ?? '' }} {{ $ticket->subject }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $ticket->number() }} · {{ $ticket->requesterStatus() }} · {{ __('opened :time', ['time' => $ticket->created_at->diffForHumans()]) }}</p>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            <x-support-thread :messages="$messages" :ticket="$ticket" />

            @if (in_array($ticket->status, ['solved', 'closed'], true) && ! $ticket->satisfaction)
                <section class="rounded-2xl bg-white p-5 text-sm shadow-xs dark:bg-gray-800">
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ __('How was the help?') }}</p>
                    <textarea wire:model="ratingComment" rows="2" class="mt-2 {{ $input }}" placeholder="{{ __('Comment (optional)') }}"></textarea>
                    <div class="mt-2 flex gap-2">
                        <x-secondary-button type="button" wire:click="rate('good')">👍 {{ __('Good') }}</x-secondary-button>
                        <x-secondary-button type="button" wire:click="rate('bad')">👎 {{ __('Not good') }}</x-secondary-button>
                    </div>
                </section>
            @endif

            @if ($ticket->status === 'closed')
                <p class="rounded-xl bg-gray-100 p-4 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('This request is closed.') }} <a href="{{ route('support.index', ['new' => 1]) }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-400">{{ __('Open a new request') }}</a></p>
            @else
                <form wire:submit="send" class="space-y-3 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ $ticket->status === 'solved' ? __('Still not solved? Answer and the request opens again.') : __('Your answer') }}
                        <textarea wire:model="body" rows="4" class="mt-1 {{ $input }}"></textarea>
                    </label>
                    <x-input-error :messages="$errors->get('body')" />
                    <input type="file" wire:model="files" multiple accept="image/*,.pdf,.txt,.log" aria-label="{{ __('Attachment') }}" class="block w-full text-sm text-gray-600 file:me-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
                    <x-input-error :messages="$errors->get('files')" /><x-input-error :messages="$errors->get('files.*')" />
                    <div class="flex flex-wrap items-center gap-2">
                        <x-primary-button wire:loading.attr="disabled" wire:target="send,files">{{ __('Send') }}</x-primary-button>
                        @if (in_array($ticket->status, \App\Models\SupportTicket::UNSOLVED, true))
                            <button type="button" wire:click="markSolved" class="text-sm text-gray-600 underline dark:text-gray-300">✓ {{ __('My problem is solved') }}</button>
                        @endif
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
