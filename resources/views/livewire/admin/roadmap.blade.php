<?php

use App\Models\RoadmapMilestone;
use App\Models\SupportTicket;
use App\Support\Helpdesk\Tickets;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Admin › Roadmap: milestones of the further development with the bugs and feature requests
 * planned into them, progress, and the backlog of everything not planned yet.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public bool $showDone = false;

    public ?int $editingId = null;

    /** @var array{title: string, version: string, due_on: string, status: string, description: string} */
    public array $form = ['title' => '', 'version' => '', 'due_on' => '', 'status' => 'planned', 'description' => ''];

    public bool $formOpen = false;

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function startCreate(): void
    {
        $this->authorize('admin');
        $this->reset('form', 'editingId');
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function startEdit(int $id): void
    {
        $this->authorize('admin');
        $milestone = RoadmapMilestone::findOrFail($id);
        $this->editingId = $milestone->id;
        $this->form = ['title' => $milestone->title, 'version' => (string) $milestone->version, 'due_on' => (string) $milestone->due_on?->toDateString(), 'status' => $milestone->status, 'description' => (string) $milestone->description];
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function save(): void
    {
        $this->authorize('admin');
        $data = $this->validate([
            'form.title' => ['required', 'string', 'max:255'],
            'form.version' => ['nullable', 'string', 'max:30'],
            'form.due_on' => ['nullable', 'date'],
            'form.status' => ['required', Rule::in(RoadmapMilestone::STATUSES)],
            'form.description' => ['nullable', 'string', 'max:5000'],
        ], attributes: ['form.title' => __('Title')])['form'];
        $data = array_map(fn ($value) => $value === '' ? null : $value, $data);

        $milestone = $this->editingId ? RoadmapMilestone::findOrFail($this->editingId) : new RoadmapMilestone(['position' => (int) RoadmapMilestone::max('position') + 1]);
        $milestone->fill($data)->save();
        SecurityLog::record(Auth::user(), 'admin_action', details: 'milestone_saved #'.$milestone->id);
        $this->reset('form', 'editingId', 'formOpen');
    }

    public function deleteMilestone(int $id): void
    {
        $this->authorize('admin');
        RoadmapMilestone::findOrFail($id)->delete();
        $this->reset('form', 'editingId', 'formOpen');
    }

    public function move(int $id, int $direction): void
    {
        $this->authorize('admin');
        $milestones = RoadmapMilestone::orderBy('position')->orderBy('id')->get()->values();
        $index = $milestones->search(fn (RoadmapMilestone $milestone) => $milestone->id === $id);
        $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);

        if ($target === null || ! isset($milestones[$target])) {
            return;
        }

        $order = $milestones->pluck('id')->all();
        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        foreach ($order as $position => $milestoneId) {
            RoadmapMilestone::whereKey($milestoneId)->update(['position' => $position]);
        }
    }

    public function plan(int $ticketId, string $milestoneId): void
    {
        $this->authorize('admin');
        Tickets::change(SupportTicket::findOrFail($ticketId), Auth::user(), ['roadmap_milestone_id' => $milestoneId === '' ? null : (int) $milestoneId]);
    }

    public function with(): array
    {
        $milestones = RoadmapMilestone::query()
            ->when(! $this->showDone, fn ($query) => $query->where('status', '!=', 'done'))
            ->with(['tickets' => fn ($query) => $query->orderByRaw("case status when 'solved' then 1 when 'closed' then 1 else 0 end")->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")])
            ->orderBy('position')->orderBy('id')
            ->get();

        return [
            'milestones' => $milestones,
            'allMilestones' => RoadmapMilestone::where('status', '!=', 'done')->orderBy('position')->get(['id', 'title', 'version']),
            'backlog' => SupportTicket::whereNull('roadmap_milestone_id')->whereIn('type', ['bug', 'feature'])->unsolved()
                ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->latest()->get(),
            'statusLabels' => RoadmapMilestone::statusLabels(),
            'ticketStatus' => SupportTicket::statusLabels(),
            'priorityLabels' => SupportTicket::priorityLabels(),
            'types' => SupportTicket::typeLabels(),
            'doneCount' => RoadmapMilestone::where('status', 'done')->count(),
        ];
    }
}; ?>

@php
    $input = 'block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Administration') }}</h2>
        <x-admin-nav />
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Plan bugs and feature requests from the support into milestones.') }}</p>
                <div class="flex items-center gap-3">
                    @if ($doneCount)
                        <label class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="showDone" class="rounded-sm border-gray-300 text-indigo-600"> {{ trans_choice(':count finished milestone|:count finished milestones', $doneCount) }}</label>
                    @endif
                    <x-primary-button type="button" wire:click="startCreate">+ {{ __('Milestone') }}</x-primary-button>
                </div>
            </div>

            @if ($formOpen)
                <form wire:submit="save" class="space-y-3 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <div class="grid gap-3 sm:grid-cols-[1fr_8rem_10rem_10rem]">
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Title') }}<input type="text" wire:model="form.title" class="mt-1 {{ $input }}" placeholder="{{ __('e.g. Shop & licenses') }}"></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Version') }}<input type="text" wire:model="form.version" class="mt-1 {{ $input }}" placeholder="1.40"></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Target date') }}<input type="date" wire:model="form.due_on" class="mt-1 {{ $input }}"></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Status') }}
                            <select wire:model="form.status" class="mt-1 {{ $input }}">@foreach ($statusLabels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                        </label>
                    </div>
                    <x-input-error :messages="$errors->get('form.title')" />
                    <textarea wire:model="form.description" rows="3" class="{{ $input }}" placeholder="{{ __('Goal and scope (optional)') }}" aria-label="{{ __('Description') }}"></textarea>
                    <div class="flex flex-wrap gap-2">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('formOpen', false)">{{ __('Cancel') }}</x-secondary-button>
                        @if ($editingId)
                            <x-danger-button type="button" wire:click="deleteMilestone({{ $editingId }})" wire:confirm="{{ __('Delete the milestone? Its tickets go back to the backlog.') }}">{{ __('Delete') }}</x-danger-button>
                        @endif
                    </div>
                </form>
            @endif

            @foreach ($milestones as $milestone)
                @php($total = $milestone->tickets->count())
                @php($finished = $milestone->tickets->whereIn('status', ['solved', 'closed'])->count())
                <section wire:key="milestone-{{ $milestone->id }}" class="rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                    <header class="flex flex-wrap items-start justify-between gap-2 px-5 pt-5">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">🏁 {{ $milestone->title }} @if ($milestone->version)<span class="ms-1 rounded-sm bg-gray-100 px-1.5 py-0.5 text-xs font-normal text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $milestone->version }}</span>@endif</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $statusLabels[$milestone->status] }}@if ($milestone->due_on) · {{ __('target :date', ['date' => $milestone->due_on->format('d.m.Y')]) }}@if ($milestone->status !== 'done' && $milestone->due_on->isPast()) <span class="text-red-600 dark:text-red-400">({{ __('overdue') }})</span>@endif @endif · {{ $finished }}/{{ $total }} {{ __('done') }}</p>
                            @if ($milestone->description)<p class="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $milestone->description }}</p>@endif
                        </div>
                        <div class="flex items-center gap-1 text-sm">
                            <button type="button" wire:click="move({{ $milestone->id }}, -1)" class="rounded-sm px-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Move up') }}">↑</button>
                            <button type="button" wire:click="move({{ $milestone->id }}, 1)" class="rounded-sm px-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Move down') }}">↓</button>
                            <button type="button" wire:click="startEdit({{ $milestone->id }})" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Edit') }}</button>
                        </div>
                    </header>
                    <div class="mx-5 mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $total ? round($finished / $total * 100) : 0 }}">
                        <div class="h-full rounded-full bg-emerald-500" style="width: {{ $total ? round($finished / $total * 100) : 0 }}%"></div>
                    </div>
                    <ul class="mt-3 divide-y divide-gray-100 pb-2 text-sm dark:divide-gray-700">
                        @forelse ($milestone->tickets as $ticket)
                            @include('livewire.admin.partials.roadmap-ticket', ['ticket' => $ticket])
                        @empty
                            <li class="px-5 py-2 text-gray-500 dark:text-gray-400">{{ __('Nothing planned yet – choose a milestone for tickets in the backlog.') }}</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach

            <section class="rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <h3 class="px-5 pt-5 font-semibold text-gray-900 dark:text-gray-100">📦 {{ __('Backlog') }} <span class="text-sm font-normal text-gray-500">({{ $backlog->count() }})</span></h3>
                <p class="px-5 text-xs text-gray-500 dark:text-gray-400">{{ __('Unsolved bugs and feature requests without a milestone.') }}</p>
                <ul class="mt-3 divide-y divide-gray-100 pb-2 text-sm dark:divide-gray-700">
                    @forelse ($backlog as $ticket)
                        @include('livewire.admin.partials.roadmap-ticket', ['ticket' => $ticket])
                    @empty
                        <li class="px-5 py-2 text-gray-500 dark:text-gray-400">{{ __('The backlog is empty.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</div>
