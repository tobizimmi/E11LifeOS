<?php

use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Helpdesk\Tickets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Admin › Support: the ticket queue with views like in a help desk (unassigned, mine, all open,
 * waiting, overdue, bugs, feature requests, solved), search and filters.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public string $view = 'open';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $tag = '';

    public bool $creating = false;

    public string $newType = 'bug';

    public string $newSubject = '';

    public string $newBody = '';

    public string $newPriority = 'normal';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    /**
     * @return array<string, array{icon: string, label: string, query: \Closure}>
     */
    private function views(): array
    {
        $me = Auth::id();

        return [
            'open' => ['icon' => '📥', 'label' => __('All unsolved'), 'query' => fn (Builder $query) => $query->unsolved()],
            'unassigned' => ['icon' => '🙋', 'label' => __('Unassigned'), 'query' => fn (Builder $query) => $query->unsolved()->whereNull('assignee_id')],
            'mine' => ['icon' => '👤', 'label' => __('Mine'), 'query' => fn (Builder $query) => $query->unsolved()->where('assignee_id', $me)],
            'new' => ['icon' => '🆕', 'label' => __('New'), 'query' => fn (Builder $query) => $query->where('status', 'new')],
            'overdue' => ['icon' => '⏰', 'label' => __('Overdue'), 'query' => fn (Builder $query) => $query->unsolved()->whereNull('first_response_at')->where('first_response_due_at', '<', now())],
            'pending' => ['icon' => '⏳', 'label' => __('Waiting for requester'), 'query' => fn (Builder $query) => $query->whereIn('status', ['pending', 'on_hold'])],
            'bugs' => ['icon' => '🐞', 'label' => __('Bugs'), 'query' => fn (Builder $query) => $query->unsolved()->where('type', 'bug')],
            'features' => ['icon' => '💡', 'label' => __('Feature requests'), 'query' => fn (Builder $query) => $query->unsolved()->where('type', 'feature')],
            'solved' => ['icon' => '✅', 'label' => __('Solved & closed'), 'query' => fn (Builder $query) => $query->whereIn('status', ['solved', 'closed'])],
        ];
    }

    /**
     * An internal bug or feature request (e.g. found while testing) for the roadmap.
     */
    public function createInternal(): void
    {
        $this->authorize('admin');
        $this->validate([
            'newType' => ['required', Rule::in(array_keys(SupportTicket::typeLabels()))],
            'newSubject' => ['required', 'string', 'max:150'],
            'newBody' => ['required', 'string', 'max:10000'],
            'newPriority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
        ], attributes: ['newSubject' => __('Subject'), 'newBody' => __('Description')]);

        $ticket = Tickets::open(Auth::user(), ['subject' => $this->newSubject, 'body' => $this->newBody, 'type' => $this->newType, 'priority' => $this->newPriority], [], Auth::user());
        $ticket->forceFill(['status' => 'open', 'assignee_id' => Auth::id()])->save();
        $this->redirectRoute('admin.support.ticket', $ticket, navigate: true);
    }

    public function with(): array
    {
        $views = $this->views();
        $current = $views[$this->view] ?? $views['open'];
        $term = trim($this->search);

        $tickets = SupportTicket::query()
            ->with(['requester:id,name,email', 'assignee:id,name', 'household:id,name', 'milestone:id,title'])
            ->tap($current['query'])
            ->when($this->priority !== '', fn ($query) => $query->where('priority', $this->priority))
            ->when($this->tag !== '', fn ($query) => $query->whereJsonContains('tags', $this->tag))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('subject', 'like', '%'.$term.'%')
                ->when(ctype_digit(ltrim($term, '#')), fn ($query) => $query->orWhere('id', (int) ltrim($term, '#')))
                ->orWhereHas('requester', fn ($query) => $query->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'))))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->latest('last_activity_at')
            ->limit(200)
            ->get();

        return [
            'views' => $views,
            'counts' => collect($views)->map(fn (array $view) => SupportTicket::query()->tap($view['query'])->count())->all(),
            'tickets' => $tickets,
            'statusLabels' => SupportTicket::statusLabels(),
            'priorityLabels' => SupportTicket::priorityLabels(),
            'types' => SupportTicket::typeLabels(),
            'stats' => [
                'open' => SupportTicket::unsolved()->count(),
                'avgFirstResponse' => (int) round((float) SupportTicket::whereNotNull('first_response_at')->where('created_at', '>=', now()->subDays(30))->get(['created_at', 'first_response_at'])->avg(fn (SupportTicket $ticket) => $ticket->created_at->diffInMinutes($ticket->first_response_at))),
                'satisfaction' => ($rated = SupportTicket::whereNotNull('satisfaction')->count()) ? (int) round(SupportTicket::where('satisfaction', 'good')->count() / $rated * 100) : null,
            ],
        ];
    }
}; ?>

@php
    $input = 'block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $priorityClass = ['urgent' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200', 'high' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200', 'normal' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200', 'low' => 'bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400'];
    $statusClass = ['new' => 'bg-amber-400 text-amber-950', 'open' => 'bg-red-500 text-white', 'pending' => 'bg-sky-500 text-white', 'on_hold' => 'bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-900', 'solved' => 'bg-emerald-600 text-white', 'closed' => 'bg-gray-300 text-gray-700 dark:bg-gray-600 dark:text-gray-200'];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Administration') }}</h2>
        <x-admin-nav />
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-2xl bg-white p-3 shadow-xs sm:p-4 dark:bg-gray-800"><p class="text-[11px] leading-tight text-gray-500 hyphens-auto sm:text-xs dark:text-gray-400">{{ __('Unsolved') }}</p><p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $stats['open'] }}</p></div>
                <div class="rounded-2xl bg-white p-3 shadow-xs sm:p-4 dark:bg-gray-800"><p class="text-[11px] leading-tight text-gray-500 hyphens-auto sm:text-xs dark:text-gray-400">{{ __('Ø first answer (30 days)') }}</p><p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $stats['avgFirstResponse'] ? ($stats['avgFirstResponse'] >= 60 ? round($stats['avgFirstResponse'] / 60, 1).' h' : $stats['avgFirstResponse'].' min') : '–' }}</p></div>
                <div class="rounded-2xl bg-white p-3 shadow-xs sm:p-4 dark:bg-gray-800"><p class="text-[11px] leading-tight text-gray-500 hyphens-auto sm:text-xs dark:text-gray-400">{{ __('Satisfaction') }}</p><p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $stats['satisfaction'] !== null ? $stats['satisfaction'].' %' : '–' }}</p></div>
            </div>

            <x-section-nav tab-property="view" :active="$view" :label="__('Views')" :groups="[
                __('Queue') => collect($views)->only(['open', 'unassigned', 'mine', 'new', 'overdue', 'pending'])->map(fn ($item, $key) => [$item['icon'], $item['label'], null, $counts[$key] ?: null])->all(),
                __('Development') => collect($views)->only(['bugs', 'features'])->map(fn ($item, $key) => [$item['icon'], $item['label'], null, $counts[$key] ?: null])->all(),
                __('Archive') => collect($views)->only(['solved'])->map(fn ($item, $key) => [$item['icon'], $item['label'], null, $counts[$key] ?: null])->all(),
            ]" />

            <div class="flex flex-wrap gap-2">
                <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }} min-w-0 flex-1" placeholder="{{ __('Search subject, #number, name or e-mail') }}" aria-label="{{ __('Search') }}">
                <select wire:model.live="priority" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Priority') }}">
                    <option value="">{{ __('All priorities') }}</option>
                    @foreach ($priorityLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
                <a href="{{ route('admin.support.macros') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-xs transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 pointer-coarse:min-h-11">💬 {{ __('Canned responses') }}</a>
                <x-secondary-button type="button" wire:click="$toggle('creating')">+ {{ __('Internal ticket') }}</x-secondary-button>
            </div>
            @if ($tag !== '')
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Tag') }}: <span class="font-mono">#{{ $tag }}</span> <button type="button" wire:click="$set('tag', '')" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Remove') }}</button></p>
            @endif

            @if ($creating)
                <form wire:submit="createInternal" class="space-y-3 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('For bugs and ideas from the team – they land in the roadmap planning. Nobody is notified.') }}</p>
                    <div class="grid gap-3 sm:grid-cols-[auto_1fr_auto]">
                        <select wire:model="newType" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Type') }}">
                            @foreach ($types as $key => $type)<option value="{{ $key }}">{{ $type['icon'] }} {{ $type['label'] }}</option>@endforeach
                        </select>
                        <input type="text" wire:model="newSubject" class="{{ $input }}" placeholder="{{ __('Subject') }}" aria-label="{{ __('Subject') }}">
                        <select wire:model="newPriority" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Priority') }}">
                            @foreach ($priorityLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <x-input-error :messages="$errors->get('newSubject')" />
                    <textarea wire:model="newBody" rows="4" class="{{ $input }}" placeholder="{{ __('Description') }}" aria-label="{{ __('Description') }}"></textarea>
                    <x-input-error :messages="$errors->get('newBody')" />
                    <x-primary-button>{{ __('Create') }}</x-primary-button>
                </form>
            @endif

            <ul class="divide-y divide-gray-100 rounded-2xl bg-white shadow-xs dark:divide-gray-700 dark:bg-gray-800">
                @forelse ($tickets as $ticket)
                    <li wire:key="ticket-{{ $ticket->id }}">
                        <a href="{{ route('admin.support.ticket', $ticket) }}" wire:navigate class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <span @class(['mt-0.5 shrink-0 rounded-sm px-1.5 py-0.5 text-center text-[10px] font-semibold uppercase sm:w-20 sm:text-[11px]', $statusClass[$ticket->status] ?? ''])>{{ $statusLabels[$ticket->status] ?? $ticket->status }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="line-clamp-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                    @if ($ticket->agent_unread)<span class="me-1 inline-block h-2 w-2 rounded-full bg-indigo-500" aria-label="{{ __('Unread') }}"></span>@endif
                                    {{ $types[$ticket->type]['icon'] ?? '' }} {{ $ticket->subject }}
                                </span>
                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $ticket->number() }} · {{ $ticket->requester?->name }}@if ($ticket->household) · 🏠 {{ $ticket->household->name }}@endif · {{ $ticket->last_activity_at?->diffForHumans() }}
                                    @if ($ticket->assignee) · 👤 {{ $ticket->assignee->name }}@endif
                                    @if ($ticket->milestone) · 🏁 {{ $ticket->milestone->title }}@endif
                                    @foreach ($ticket->tags ?? [] as $ticketTag) <span class="font-mono">#{{ $ticketTag }}</span>@endforeach
                                </span>
                            </span>
                            <span class="flex shrink-0 flex-col items-end gap-1">
                                <span @class(['rounded-sm px-1.5 py-0.5 text-[11px]', $priorityClass[$ticket->priority] ?? ''])>{{ $priorityLabels[$ticket->priority] ?? $ticket->priority }}</span>
                                @if ($ticket->isOverdue())
                                    <span class="text-[11px] font-medium text-red-600 dark:text-red-400">⏰ {{ __('overdue') }}</span>
                                @elseif ($ticket->first_response_at === null && $ticket->first_response_due_at && in_array($ticket->status, \App\Models\SupportTicket::UNSOLVED, true))
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('answer :time', ['time' => $ticket->first_response_due_at->diffForHumans()]) }}</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No tickets in this view.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
