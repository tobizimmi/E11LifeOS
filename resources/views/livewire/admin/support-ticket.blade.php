<?php

use App\Models\RoadmapMilestone;
use App\Models\SupportTicket;
use App\Support\Helpdesk\Macros;
use App\Support\Helpdesk\Tickets;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Admin › Support › one ticket: conversation with public replies and internal notes, "submit as"
 * status, and the ticket fields (priority, type, assignee, milestone, area, tags) with history.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $ticketId;

    public string $body = '';

    public bool $internal = false;

    public string $submitAs = 'pending';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    public string $tags = '';

    public function mount(SupportTicket $ticket): void
    {
        $this->authorize('admin');
        $this->ticketId = $ticket->id;
        $this->tags = implode(', ', $ticket->tags ?? []);

        if ($ticket->agent_unread) {
            $ticket->forceFill(['agent_unread' => false])->save();
        }
    }

    /**
     * Notes keep the status by default, public answers wait for the requester.
     */
    public function updatedInternal(): void
    {
        $status = $this->ticket()->status;
        $this->submitAs = $this->internal ? (in_array($status, ['open', 'pending', 'on_hold', 'solved'], true) ? $status : 'open') : 'pending';
    }

    private function ticket(): SupportTicket
    {
        $this->authorize('admin');

        return SupportTicket::findOrFail($this->ticketId);
    }

    public function send(): void
    {
        $ticket = $this->ticket();
        $this->validate([
            'body' => ['required', 'string', 'max:10000'],
            'submitAs' => ['required', Rule::in(SupportTicket::STATUSES)],
            'files' => ['array', 'max:5'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf,txt,log'],
        ], attributes: ['body' => __('Answer'), 'files.*' => __('Attachment')]);

        Tickets::reply($ticket, Auth::user(), $this->body, array_values($this->files), $this->internal, $this->submitAs, asAgent: true);
        $this->reset('body', 'files');
        $this->dispatch('toast', message: $this->internal ? __('Note saved.') : __('Answer sent.'));
    }

    /**
     * Puts a canned response into the answer (after what is already written) and takes its status.
     */
    public function applyMacro(string $id): void
    {
        $macro = Macros::find($id);
        abort_if($macro === null, 404);
        $text = Macros::render($macro, $this->ticket(), Auth::user());
        $this->body = trim($this->body) !== '' ? rtrim($this->body)."\n\n".$text : $text;
        $this->internal = false;
        $this->submitAs = $macro['status'] ?? $this->submitAs;
    }

    public function setField(string $field, string $value): void
    {
        abort_unless(in_array($field, ['status', 'priority', 'type', 'assignee_id', 'roadmap_milestone_id', 'area'], true), 422);
        $value = $value === '' ? null : (in_array($field, ['assignee_id', 'roadmap_milestone_id'], true) ? (int) $value : $value);
        abort_if($value === null && in_array($field, ['status', 'priority', 'type'], true), 422);
        Tickets::change($this->ticket(), Auth::user(), [$field => $value]);
    }

    public function saveTags(): void
    {
        $this->validate(['tags' => ['nullable', 'string', 'max:300']]);
        $ticket = $this->ticket();
        Tickets::change($ticket, Auth::user(), ['tags' => preg_split('/[,\s]+/', $this->tags, -1, PREG_SPLIT_NO_EMPTY)]);
        $this->tags = implode(', ', $ticket->fresh()->tags ?? []);
    }

    public function takeIt(): void
    {
        Tickets::change($this->ticket(), Auth::user(), ['assignee_id' => Auth::id()]);
    }

    public function delete(): void
    {
        $ticket = $this->ticket();
        \App\Support\Security\SecurityLog::record(Auth::user(), 'admin_action', details: 'support_ticket_deleted #'.$ticket->id);
        $ticket->delete();
        $this->redirectRoute('admin.support', navigate: true);
    }

    public function with(): array
    {
        $ticket = $this->ticket()->load(['requester', 'household:id,name', 'assignee:id,name']);

        return [
            'ticket' => $ticket,
            'messages' => $ticket->messages()->with('author:id,name')->get(),
            'agents' => Tickets::agents(),
            'macros' => Macros::all(),
            'milestones' => RoadmapMilestone::where('status', '!=', 'done')->orWhere('id', $ticket->roadmap_milestone_id)->orderBy('position')->orderBy('due_on')->get(),
            'statusLabels' => SupportTicket::statusLabels(),
            'priorityLabels' => SupportTicket::priorityLabels(),
            'types' => SupportTicket::typeLabels(),
            'areas' => ModuleCatalog::all(),
            'requesterTickets' => $ticket->requester ? SupportTicket::where('user_id', $ticket->user_id)->whereKeyNot($ticket->id)->latest()->limit(5)->get(['id', 'subject', 'status']) : collect(),
        ];
    }
}; ?>

@php
    $input = 'block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $select = 'block w-full rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <a href="{{ route('admin.support') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400">‹ {{ __('Support') }}</a>
        <h2 class="mt-1 font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $types[$ticket->type]['icon'] ?? '' }} {{ $ticket->subject }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ $ticket->number() }} · {{ $statusLabels[$ticket->status] }} · {{ __('opened :time', ['time' => $ticket->created_at->diffForHumans()]) }}
            @if ($ticket->isOverdue()) · <span class="font-medium text-red-600 dark:text-red-400">⏰ {{ __('first answer overdue since :time', ['time' => $ticket->first_response_due_at->diffForHumans()]) }}</span>
            @elseif ($ticket->first_response_at === null && $ticket->first_response_due_at && in_array($ticket->status, \App\Models\SupportTicket::UNSOLVED, true)) · {{ __('first answer due :time', ['time' => $ticket->first_response_due_at->diffForHumans()]) }}
            @endif
        </p>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto grid max-w-6xl gap-6 px-4 sm:px-6 lg:grid-cols-[1fr_18rem] lg:px-8">
            <div class="min-w-0 space-y-4">
                <x-support-thread :messages="$messages" :ticket="$ticket" agent-view />

                <form wire:submit="send" @class(['space-y-3 rounded-2xl p-4 shadow-xs', 'bg-amber-50 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:ring-amber-800' => $internal, 'bg-white dark:bg-gray-800' => ! $internal])>
                    <div class="flex gap-1 rounded-lg bg-gray-100 p-1 text-sm dark:bg-gray-900/60" role="tablist">
                        <button type="button" role="tab" aria-selected="{{ $internal ? 'false' : 'true' }}" wire:click="$set('internal', false)" @class(['flex-1 rounded-md px-3 py-1.5', 'bg-white font-medium shadow-xs dark:bg-gray-700 dark:text-gray-100' => ! $internal, 'text-gray-600 dark:text-gray-400' => $internal])>💬 {{ __('Public answer') }}</button>
                        <button type="button" role="tab" aria-selected="{{ $internal ? 'true' : 'false' }}" wire:click="$set('internal', true)" @class(['flex-1 rounded-md px-3 py-1.5', 'bg-white font-medium shadow-xs dark:bg-gray-700 dark:text-gray-100' => $internal, 'text-gray-600 dark:text-gray-400' => ! $internal])>🔒 {{ __('Internal note') }}</button>
                    </div>
                    @if (! $internal)
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            @if ($macros !== [])
                                <select x-on:change="if ($event.target.value) { $wire.applyMacro($event.target.value); $event.target.value = '' }" class="rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Insert canned response') }}">
                                    <option value="">💬 {{ __('Insert canned response') }}</option>
                                    @foreach ($macros as $macro)<option value="{{ $macro['id'] }}">{{ $macro['title'] }}</option>@endforeach
                                </select>
                            @endif
                            <a href="{{ route('admin.support.macros') }}" wire:navigate class="text-xs text-indigo-600 underline dark:text-indigo-400">{{ $macros === [] ? __('Create canned responses') : __('Manage') }}</a>
                        </div>
                    @endif
                    <textarea wire:model="body" rows="5" class="{{ $input }}" aria-label="{{ $internal ? __('Internal note') : __('Public answer') }}" placeholder="{{ $internal ? __('Only administrators see this note.') : __('The requester gets this answer by push and e-mail.') }}"></textarea>
                    <x-input-error :messages="$errors->get('body')" />
                    <input type="file" wire:model="files" multiple accept="image/*,.pdf,.txt,.log" aria-label="{{ __('Attachment') }}" class="block w-full text-sm text-gray-600 file:me-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
                    <x-input-error :messages="$errors->get('files.*')" />
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <label class="text-sm text-gray-600 dark:text-gray-300">{{ __('Submit as') }}</label>
                        <select wire:model="submitAs" class="rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Submit as') }}">
                            @foreach (['open', 'pending', 'on_hold', 'solved'] as $status)<option value="{{ $status }}">{{ $statusLabels[$status] }}</option>@endforeach
                        </select>
                        <x-primary-button wire:loading.attr="disabled" wire:target="send,files">{{ $internal ? __('Save note') : __('Send') }}</x-primary-button>
                    </div>
                </form>
            </div>

            <aside class="space-y-4 text-sm">
                <section class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Requester') }}</p>
                    <p class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $ticket->requester?->name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $ticket->requester?->email }}@if ($ticket->household) · 🏠 {{ $ticket->household->name }}@endif</p>
                    @if ($ticket->context)
                        <dl class="mt-2 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                            @if ($ticket->context['url'] ?? null)<div class="truncate">🔗 <span title="{{ $ticket->context['url'] }}">{{ $ticket->context['url'] }}</span></div>@endif
                            @if ($ticket->context['version'] ?? null)<div>🏷 {{ $ticket->context['version'] }}</div>@endif
                            @if ($ticket->context['agent'] ?? null)<div class="truncate" title="{{ $ticket->context['agent'] }}">💻 {{ $ticket->context['agent'] }}</div>@endif
                        </dl>
                    @endif
                    @if ($requesterTickets->isNotEmpty())
                        <p class="mt-3 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('Other requests') }}</p>
                        <ul class="mt-1 space-y-0.5 text-xs">
                            @foreach ($requesterTickets as $other)
                                <li><a href="{{ route('admin.support.ticket', $other) }}" wire:navigate class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $other->number() }} {{ \Illuminate\Support\Str::limit($other->subject, 40) }}</a> <span class="text-gray-400">({{ $statusLabels[$other->status] }})</span></li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($ticket->satisfaction)
                        <p class="mt-3 text-xs">{{ $ticket->satisfaction === 'good' ? '👍' : '👎' }} {{ $ticket->satisfaction_comment }}</p>
                    @endif
                </section>

                <section class="space-y-3 rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Status') }}
                        <select wire:change="setField('status', $event.target.value)" class="mt-1 {{ $select }}">
                            @foreach ($statusLabels as $key => $label)<option value="{{ $key }}" @selected($ticket->status === $key)>{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Assignee') }}
                        <select wire:change="setField('assignee_id', $event.target.value)" class="mt-1 {{ $select }}">
                            <option value="">– {{ __('nobody') }} –</option>
                            @foreach ($agents as $agent)<option value="{{ $agent->id }}" @selected($ticket->assignee_id === $agent->id)>{{ $agent->name }}</option>@endforeach
                        </select>
                    </label>
                    @if ($ticket->assignee_id !== auth()->id())
                        <button type="button" wire:click="takeIt" class="text-xs text-indigo-600 underline dark:text-indigo-400">{{ __('Take it') }}</button>
                    @endif
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Priority') }}
                            <select wire:change="setField('priority', $event.target.value)" class="mt-1 {{ $select }}">
                                @foreach ($priorityLabels as $key => $label)<option value="{{ $key }}" @selected($ticket->priority === $key)>{{ $label }}</option>@endforeach
                            </select>
                        </label>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Type') }}
                            <select wire:change="setField('type', $event.target.value)" class="mt-1 {{ $select }}">
                                @foreach ($types as $key => $type)<option value="{{ $key }}" @selected($ticket->type === $key)>{{ $type['label'] }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Area') }}
                        <select wire:change="setField('area', $event.target.value)" class="mt-1 {{ $select }}">
                            <option value="">– {{ __('none') }} –</option>
                            @foreach ($areas as $key => $module)<option value="{{ $key }}" @selected($ticket->area === $key)>{{ $module['icon'] }} {{ $module['label'] }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Milestone') }}
                        <select wire:change="setField('roadmap_milestone_id', $event.target.value)" class="mt-1 {{ $select }}">
                            <option value="">– {{ __('Backlog') }} –</option>
                            @foreach ($milestones as $milestone)<option value="{{ $milestone->id }}" @selected($ticket->roadmap_milestone_id === $milestone->id)>{{ $milestone->title }}@if ($milestone->version) ({{ $milestone->version }})@endif</option>@endforeach
                        </select>
                    </label>
                    <form wire:submit="saveTags">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Tags') }}
                            <input type="text" wire:model="tags" wire:blur="saveTags" class="mt-1 {{ $select }}" placeholder="{{ __('e.g. ios, login') }}">
                        </label>
                    </form>
                </section>

                <button type="button" wire:click="delete" wire:confirm="{{ __('Delete this ticket with all messages and files for good?') }}" class="text-xs text-red-600 underline dark:text-red-400">{{ __('Delete ticket') }}</button>
            </aside>
        </div>
    </div>
</div>
