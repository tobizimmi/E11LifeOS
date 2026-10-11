<?php

use App\Models\RoadmapMilestone;
use App\Models\RoadmapMilestoneImage;
use App\Models\SupportTicket;
use App\Support\Helpdesk\RoadmapExport;
use App\Support\Helpdesk\RoadmapImages;
use App\Support\Helpdesk\Tickets;
use App\Support\Security\SecurityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Admin › Roadmap: milestones of the further development with the bugs and feature requests
 * planned into them, progress, and the backlog of everything not planned yet.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    #[Url]
    public bool $showDone = false;

    public ?int $editingId = null;

    /** @var array{title: string, version: string, due_on: string, status: string, description: string, details: string} */
    public array $form = ['title' => '', 'version' => '', 'due_on' => '', 'status' => 'planned', 'description' => '', 'details' => ''];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> images to attach on save */
    public array $newImages = [];

    /** @var array<int, string> caption per existing image id, editable in the form */
    public array $imageCaptions = [];

    public bool $formOpen = false;

    /** @var array<int|string, array{title: string, type: string, priority: string}> new item per milestone ("backlog" for none) */
    public array $newItem = [];

    /** Markdown shown in the export window (null = closed). */
    public ?string $export = null;

    public ?int $exportMilestone = null;

    public bool $exportWithDone = false;

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function startCreate(): void
    {
        $this->authorize('admin');
        $this->reset('form', 'editingId', 'newImages', 'imageCaptions');
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function startEdit(int $id): void
    {
        $this->authorize('admin');
        $milestone = RoadmapMilestone::with('images')->findOrFail($id);
        $this->editingId = $milestone->id;
        $this->form = ['title' => $milestone->title, 'version' => (string) $milestone->version, 'due_on' => (string) $milestone->due_on?->toDateString(), 'status' => $milestone->status, 'description' => (string) $milestone->description, 'details' => (string) $milestone->details];
        $this->imageCaptions = $milestone->images->mapWithKeys(fn (RoadmapMilestoneImage $image) => [$image->id => (string) $image->caption])->all();
        $this->reset('newImages');
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function save(): void
    {
        $this->authorize('admin');
        $this->validate([
            'form.title' => ['required', 'string', 'max:255'],
            'form.version' => ['nullable', 'string', 'max:30'],
            'form.due_on' => ['nullable', 'date'],
            'form.status' => ['required', Rule::in(RoadmapMilestone::STATUSES)],
            'form.description' => ['nullable', 'string', 'max:5000'],
            'form.details' => ['nullable', 'string', 'max:20000'],
            'newImages' => ['nullable', 'array', 'max:20'],
            'newImages.*' => ['image', 'max:8192'],
            'imageCaptions.*' => ['nullable', 'string', 'max:255'],
        ], attributes: ['form.title' => __('Title'), 'newImages.*' => __('Image')]);

        $data = array_map(fn ($value) => $value === '' ? null : $value, $this->form);

        $milestone = $this->editingId ? RoadmapMilestone::findOrFail($this->editingId) : new RoadmapMilestone(['position' => (int) RoadmapMilestone::max('position') + 1]);
        $milestone->fill($data)->save();

        foreach ($milestone->images()->whereIn('id', array_keys($this->imageCaptions))->get() as $image) {
            $caption = trim((string) ($this->imageCaptions[$image->id] ?? ''));
            $image->update(['caption' => $caption !== '' ? $caption : null]);
        }

        foreach ($this->newImages as $upload) {
            RoadmapImages::store($milestone, $upload);
        }

        SecurityLog::record(Auth::user(), 'admin_action', details: 'milestone_saved #'.$milestone->id);
        $this->reset('form', 'editingId', 'formOpen', 'newImages', 'imageCaptions');
    }

    public function deleteImage(int $imageId): void
    {
        $this->authorize('admin');
        $image = RoadmapMilestoneImage::findOrFail($imageId);
        RoadmapImages::delete($image);
        unset($this->imageCaptions[$imageId]);
    }

    public function deleteMilestone(int $id): void
    {
        $this->authorize('admin');
        $milestone = RoadmapMilestone::with('images')->findOrFail($id);

        foreach ($milestone->images as $image) {
            RoadmapImages::delete($image);
        }

        $milestone->delete();
        $this->reset('form', 'editingId', 'formOpen', 'newImages', 'imageCaptions');
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

    /**
     * A roadmap item of the team, straight into a milestone or the backlog.
     */
    public function addItem(string $target): void
    {
        $this->authorize('admin');
        $this->validate([
            'newItem.'.$target.'.title' => ['required', 'string', 'max:150'],
            'newItem.'.$target.'.type' => ['nullable', Rule::in(SupportTicket::ROADMAP_TYPES)],
            'newItem.'.$target.'.priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
        ], attributes: ['newItem.'.$target.'.title' => __('Title')]);

        $item = $this->newItem[$target];
        Tickets::addItem(Auth::user(), trim($item['title']), $item['type'] ?? 'task', $item['priority'] ?? 'normal', $target === 'backlog' ? null : (int) $target);
        $this->newItem[$target] = ['title' => '', 'type' => $item['type'] ?? 'task', 'priority' => $item['priority'] ?? 'normal'];
    }

    /**
     * The roadmap (all open milestones and the backlog, or one milestone) as Markdown for Claude.
     */
    public function openExport(?int $milestoneId = null): void
    {
        $this->authorize('admin');
        $this->exportMilestone = $milestoneId;
        $this->export = RoadmapExport::markdown($milestoneId, $this->exportWithDone);
    }

    public function updatedExportWithDone(): void
    {
        if ($this->export !== null) {
            $this->export = RoadmapExport::markdown($this->exportMilestone, $this->exportWithDone);
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
            ->with(['images', 'tickets' => fn ($query) => $query->orderByRaw("case status when 'solved' then 1 when 'closed' then 1 else 0 end")->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")])
            ->orderBy('position')->orderBy('id')
            ->get();

        return [
            'milestones' => $milestones,
            'allMilestones' => RoadmapMilestone::where('status', '!=', 'done')->orderBy('position')->get(['id', 'title', 'version']),
            'backlog' => SupportTicket::whereNull('roadmap_milestone_id')->whereIn('type', SupportTicket::ROADMAP_TYPES)->unsolved()
                ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->latest()->get(),
            'statusLabels' => RoadmapMilestone::statusLabels(),
            'ticketStatus' => SupportTicket::statusLabels(),
            'priorityLabels' => SupportTicket::priorityLabels(),
            'types' => SupportTicket::typeLabels(),
            'doneCount' => RoadmapMilestone::where('status', 'done')->count(),
        ];
    }

    /**
     * Images of the milestone currently being edited (lazily evaluated and cached per
     * request, instead of running straight in the template body on every update).
     *
     * @return \Illuminate\Support\Collection<int, RoadmapMilestoneImage>
     */
    #[Computed]
    public function editImages(): \Illuminate\Support\Collection
    {
        return $this->editingId ? (RoadmapMilestone::with('images')->find($this->editingId)?->images ?? collect()) : collect();
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
                    <x-secondary-button type="button" wire:click="openExport">📤 {{ __('Export for Claude') }}</x-secondary-button>
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
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Short description') }}
                        <textarea wire:model="form.description" rows="2" class="mt-1 {{ $input }}" placeholder="{{ __('Goal and scope (optional)') }}" aria-label="{{ __('Short description') }}"></textarea>
                    </label>
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Detailed explanation') }}
                        <textarea wire:model="form.details" rows="6" class="mt-1 {{ $input }}" placeholder="{{ __('Background, requirements, acceptance criteria, open questions … (optional)') }}" aria-label="{{ __('Detailed explanation') }}"></textarea>
                    </label>
                    <x-input-error :messages="$errors->get('form.details')" />

                    <div class="space-y-2">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Images') }}</p>
                        @if ($this->editImages->isNotEmpty())
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($this->editImages as $image)
                                    <div wire:key="milestone-image-{{ $image->id }}" class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                                        <img src="{{ route('admin.roadmap.image', $image) }}" alt="{{ $image->caption }}" class="h-32 w-full bg-gray-50 object-cover dark:bg-gray-900">
                                        <div class="flex items-center gap-1 p-2">
                                            <input type="text" wire:model="imageCaptions.{{ $image->id }}" class="flex-1 rounded-md border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="{{ __('Caption (optional)') }}" aria-label="{{ __('Caption') }}">
                                            <button type="button" wire:click="deleteImage({{ $image->id }})" wire:confirm="{{ __('Delete this image?') }}" class="rounded-sm p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700" aria-label="{{ __('Delete') }}">🗑</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <input type="file" wire:model="newImages" multiple accept="image/*" class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:text-gray-700 dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('JPEG or PNG, up to 8 MB each. They are included in the export.') }}</p>
                        <div wire:loading wire:target="newImages" class="text-xs text-gray-500 dark:text-gray-400">{{ __('Uploading …') }}</div>
                        <x-input-error :messages="$errors->get('newImages.*')" />
                        @if ($newImages)
                            <div class="flex flex-wrap gap-2">
                                @foreach ($newImages as $preview)
                                    @if (is_object($preview) && method_exists($preview, 'temporaryUrl'))
                                        <img src="{{ $preview->temporaryUrl() }}" alt="" class="h-16 w-16 rounded-md object-cover ring-1 ring-gray-200 dark:ring-gray-700">
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

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
                            @if ($milestone->details)
                                <details class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                    <summary class="cursor-pointer text-indigo-600 dark:text-indigo-400">{{ __('Detailed explanation') }}</summary>
                                    <p class="mt-1 whitespace-pre-line">{{ $milestone->details }}</p>
                                </details>
                            @endif
                        </div>
                        <div class="flex items-center gap-1 text-sm">
                            <button type="button" wire:click="move({{ $milestone->id }}, -1)" class="rounded-sm px-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Move up') }}">↑</button>
                            <button type="button" wire:click="move({{ $milestone->id }}, 1)" class="rounded-sm px-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Move down') }}">↓</button>
                            <button type="button" wire:click="openExport({{ $milestone->id }})" class="rounded-sm px-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" title="{{ __('Export for Claude') }}" aria-label="{{ __('Export for Claude') }}">📤</button>
                            <button type="button" wire:click="startEdit({{ $milestone->id }})" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Edit') }}</button>
                        </div>
                    </header>
                    <div class="mx-5 mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $total ? round($finished / $total * 100) : 0 }}">
                        <div class="h-full rounded-full bg-emerald-500" style="width: {{ $total ? round($finished / $total * 100) : 0 }}%"></div>
                    </div>
                    @if ($milestone->images->isNotEmpty())
                        <div class="mx-5 mt-3 flex flex-wrap gap-2">
                            @foreach ($milestone->images as $image)
                                <a href="{{ route('admin.roadmap.image', $image) }}" target="_blank" wire:key="ms-{{ $milestone->id }}-img-{{ $image->id }}" class="group relative block" title="{{ $image->caption }}">
                                    <img src="{{ route('admin.roadmap.image', $image) }}" alt="{{ $image->caption }}" class="h-20 w-28 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-700">
                                    @if ($image->caption)<span class="absolute inset-x-0 bottom-0 truncate rounded-b-lg bg-black/50 px-1 py-0.5 text-[10px] text-white">{{ $image->caption }}</span>@endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                    <ul class="mt-3 divide-y divide-gray-100 pb-2 text-sm dark:divide-gray-700">
                        @forelse ($milestone->tickets as $ticket)
                            @include('livewire.admin.partials.roadmap-ticket', ['ticket' => $ticket])
                        @empty
                            <li class="px-5 py-2 text-gray-500 dark:text-gray-400">{{ __('Nothing planned yet – add an item below or choose a milestone for tickets in the backlog.') }}</li>
                        @endforelse
                    </ul>
                    @include('livewire.admin.partials.roadmap-add', ['target' => (string) $milestone->id])
                </section>
            @endforeach

            <section class="rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <h3 class="px-5 pt-5 font-semibold text-gray-900 dark:text-gray-100">📦 {{ __('Backlog') }} <span class="text-sm font-normal text-gray-500">({{ $backlog->count() }})</span></h3>
                <p class="px-5 text-xs text-gray-500 dark:text-gray-400">{{ __('Unsolved bugs, feature requests and items without a milestone.') }}</p>
                <ul class="mt-3 divide-y divide-gray-100 pb-2 text-sm dark:divide-gray-700">
                    @forelse ($backlog as $ticket)
                        @include('livewire.admin.partials.roadmap-ticket', ['ticket' => $ticket])
                    @empty
                        <li class="px-5 py-2 text-gray-500 dark:text-gray-400">{{ __('The backlog is empty.') }}</li>
                    @endforelse
                </ul>
                @include('livewire.admin.partials.roadmap-add', ['target' => 'backlog'])
            </section>
        </div>
    </div>

    @if ($export !== null)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/40 sm:items-center sm:p-4" x-data="{ copied: false }" x-on:keydown.escape.window="$wire.set('export', null)" role="dialog" aria-modal="true" aria-label="{{ __('Export for Claude') }}">
            <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl dark:bg-gray-800" x-trap.noscroll="true">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-700">
                    <p class="font-semibold text-gray-900 dark:text-gray-100">📤 {{ __('Export for Claude') }}</p>
                    <label class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="exportWithDone" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Include solved items') }}</label>
                </div>
                <p class="px-5 pt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Markdown with milestones, items, descriptions, detailed explanations, technical context and internal notes – without names or e-mail addresses of requesters. Paste it into Claude or save it as a file in the project. If milestones have images attached, the download is a ZIP that bundles the Markdown with those images.') }}</p>
                <textarea x-ref="markdown" readonly class="m-5 min-h-[40vh] flex-1 rounded-lg border-gray-300 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Export') }}">{{ $export }}</textarea>
                <div class="flex flex-wrap justify-end gap-2 border-t border-gray-100 px-5 py-3 dark:border-gray-700">
                    <x-secondary-button type="button" wire:click="$set('export', null)">{{ __('Close') }}</x-secondary-button>
                    <a href="{{ route('admin.roadmap.export', array_filter(['milestone' => $exportMilestone, 'done' => $exportWithDone ? 1 : null], fn ($value) => $value !== null)) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-xs hover:bg-gray-50 dark:border-gray-500 dark:bg-gray-800 dark:text-gray-300">⬇ {{ __('Download') }}</a>
                    <x-primary-button type="button" x-on:click="navigator.clipboard.writeText($refs.markdown.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                        <span x-show="! copied">📋 {{ __('Copy') }}</span><span x-show="copied" x-cloak>✓ {{ __('Copied') }}</span>
                    </x-primary-button>
                </div>
            </div>
        </div>
    @endif
</div>
