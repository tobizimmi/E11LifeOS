<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\CareerEntry;
use App\Models\ExternalLink;
use App\Support\Career\CareerPlanner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Career development: timeline of jobs, education, trainings and milestones, certificates and work
 * contracts with their documents, the CV (also as PDF), applications with job suggestions from the
 * job exchange. Personal – only the member sees it.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    private const TABS = ['overview', 'trainings', 'contracts', 'cv', 'applications'];

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public bool $sheet = false;

    public ?int $editingId = null;

    /**
     * @var array{type: string, title: string, organization: string, location: string, starts_on: string, ends_on: string, description: string, grade: string, hours: string, expires_on: string, salary: string, weekly_hours: string, vacation_days: string, notice_period: string, probation_until: string, status: string, url: string, contact: string}
     */
    public array $entry = [];

    /**
     * @var array{headline: string, summary: string, skills: string, languages: string, contact: string, desired_role: string, desired_location: string}
     */
    public array $profile = [];

    public ?int $documentsFor = null;

    public bool $loadJobs = false;

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'overview';
        $this->profile = CareerPlanner::profile(Auth::user());
        $this->resetEntry('job');
    }

    private function resetEntry(string $type): void
    {
        $this->entry = ['type' => $type, 'title' => '', 'organization' => '', 'location' => '', 'starts_on' => '', 'ends_on' => '', 'description' => '', 'grade' => '', 'hours' => '', 'expires_on' => '', 'salary' => '', 'weekly_hours' => '', 'vacation_days' => '', 'notice_period' => '', 'probation_until' => '', 'status' => 'planned', 'url' => '', 'contact' => ''];
    }

    private function entries()
    {
        return CareerEntry::where('user_id', Auth::id())->where('household_id', $this->householdId);
    }

    private function findEntry(int $id): CareerEntry
    {
        return $this->entries()->find($id) ?? abort(404);
    }

    public function add(string $type): void
    {
        abort_unless(in_array($type, CareerEntry::TYPES, true), 422);
        $this->resetEntry($type);
        $this->editingId = null;
        $this->resetValidation();
        $this->sheet = true;
    }

    public function edit(int $id): void
    {
        $entry = $this->findEntry($id);
        $this->resetEntry($entry->type);
        $this->entry = array_merge($this->entry, [
            'title' => $entry->title, 'organization' => (string) $entry->organization, 'location' => (string) $entry->location,
            'starts_on' => (string) $entry->starts_on?->toDateString(), 'ends_on' => (string) $entry->ends_on?->toDateString(),
            'description' => (string) $entry->description,
        ], array_map(fn ($value) => (string) $value, array_intersect_key((array) $entry->details, array_flip(['grade', 'hours', 'expires_on', 'salary', 'weekly_hours', 'vacation_days', 'notice_period', 'probation_until', 'status', 'url', 'contact']))));
        $this->editingId = $entry->id;
        $this->resetValidation();
        $this->sheet = true;
    }

    public function closeSheet(): void
    {
        $this->sheet = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        $data = $this->validate([
            'entry.type' => ['required', Rule::in(CareerEntry::TYPES)],
            'entry.title' => ['required', 'string', 'max:255'],
            'entry.organization' => ['nullable', 'string', 'max:255'],
            'entry.location' => ['nullable', 'string', 'max:255'],
            'entry.starts_on' => ['nullable', 'date'],
            'entry.ends_on' => ['nullable', 'date', 'after_or_equal:entry.starts_on'],
            'entry.description' => ['nullable', 'string', 'max:5000'],
            'entry.grade' => ['nullable', 'string', 'max:50'],
            'entry.hours' => ['nullable', 'string', 'max:20'],
            'entry.expires_on' => ['nullable', 'date'],
            'entry.salary' => ['nullable', 'string', 'max:100'],
            'entry.weekly_hours' => ['nullable', 'string', 'max:20'],
            'entry.vacation_days' => ['nullable', 'string', 'max:20'],
            'entry.notice_period' => ['nullable', 'string', 'max:100'],
            'entry.probation_until' => ['nullable', 'date'],
            'entry.status' => ['nullable', Rule::in(CareerEntry::APPLICATION_STATUSES)],
            'entry.url' => ['nullable', 'url:http,https', 'max:1000'],
            'entry.contact' => ['nullable', 'string', 'max:255'],
        ], attributes: ['entry.title' => __('Title'), 'entry.ends_on' => __('Until'), 'entry.url' => __('Link')])['entry'];

        $detailKeys = match ($data['type']) {
            'education' => ['grade'],
            'training' => ['hours', 'expires_on'],
            'contract' => ['salary', 'weekly_hours', 'vacation_days', 'notice_period', 'probation_until'],
            'application' => ['status', 'url', 'contact'],
            default => [],
        };
        $details = array_filter(array_map(fn ($value) => trim((string) $value), array_intersect_key($data, array_flip($detailKeys))), fn (string $value) => $value !== '');

        $attributes = [
            'type' => $data['type'],
            'title' => trim($data['title']),
            'organization' => trim((string) $data['organization']) ?: null,
            'location' => trim((string) $data['location']) ?: null,
            'starts_on' => $data['starts_on'] ?: null,
            'ends_on' => $data['ends_on'] ?: null,
            'description' => trim((string) $data['description']) ?: null,
            'details' => $details ?: null,
        ];

        if ($this->editingId) {
            $this->findEntry($this->editingId)->update($attributes);
        } else {
            CareerEntry::create($attributes + ['user_id' => Auth::id(), 'household_id' => $this->householdId]);
        }

        $this->closeSheet();
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function delete(int $id): void
    {
        $this->findEntry($id)->delete();
        $this->closeSheet();
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, CareerEntry::APPLICATION_STATUSES, true), 422);
        $entry = $this->findEntry($id);
        abort_unless($entry->type === 'application', 422);
        $entry->update(['details' => array_merge((array) $entry->details, ['status' => $status])]);
    }

    public function saveProfile(): void
    {
        $data = $this->validate([
            'profile.headline' => ['nullable', 'string', 'max:150'],
            'profile.summary' => ['nullable', 'string', 'max:3000'],
            'profile.skills' => ['nullable', 'string', 'max:2000'],
            'profile.languages' => ['nullable', 'string', 'max:500'],
            'profile.contact' => ['nullable', 'string', 'max:500'],
            'profile.desired_role' => ['nullable', 'string', 'max:150'],
            'profile.desired_location' => ['nullable', 'string', 'max:150'],
        ])['profile'];

        Auth::user()->update(['career_profile' => array_map(fn ($value) => trim((string) $value), $data)]);
        $this->dispatch('toast', message: __('Saved.'));
    }

    /**
     * Remembers a suggested job as a planned application.
     *
     * @param  array{title: string, employer: string, location: string, url: string}  $job
     */
    public function rememberJob(array $job): void
    {
        $title = mb_substr(trim((string) ($job['title'] ?? '')), 0, 255);
        $url = (string) ($job['url'] ?? '');
        abort_if($title === '' || ! str_starts_with($url, 'https://'), 422);

        if (! $this->entries()->where('type', 'application')->get()->contains(fn (CareerEntry $entry) => $entry->detail('url') === $url)) {
            CareerEntry::create([
                'user_id' => Auth::id(), 'household_id' => $this->householdId, 'type' => 'application', 'title' => $title,
                'organization' => mb_substr(trim((string) ($job['employer'] ?? '')), 0, 255) ?: null, 'location' => mb_substr(trim((string) ($job['location'] ?? '')), 0, 255) ?: null,
                'details' => ['status' => 'planned', 'url' => mb_substr($url, 0, 1000)],
            ]);
        }

        $this->dispatch('toast', message: __('Added to your applications.'));
    }

    public function toggleDocuments(int $id): void
    {
        $this->documentsFor = $this->documentsFor === $id ? null : $this->findEntry($id)->id;
    }

    public function with(): array
    {
        $user = Auth::user();
        $entries = $this->entries()->get();
        $terms = CareerPlanner::searchTerms($user, $entries);

        return [
            'entries' => $entries,
            'timeline' => CareerPlanner::timeline($entries),
            'hints' => CareerPlanner::hints($entries),
            'types' => CareerEntry::types(),
            'statuses' => CareerEntry::applicationStatuses(),
            'terms' => $terms,
            'searchLinks' => CareerPlanner::searchLinks($terms['what'], $terms['where']),
            'jobs' => $this->tab === 'applications' && $this->loadJobs ? CareerPlanner::jobSuggestions($terms['what'], $terms['where']) : null,
            'documentCounts' => ExternalLink::where('linkable_type', 'career_entry')->where('household_id', $this->householdId)->whereIn('linkable_id', $entries->pluck('id'))
                ->selectRaw('linkable_id, COUNT(*) as total')->groupBy('linkable_id')->pluck('total', 'linkable_id'),
            'skills' => CareerPlanner::skills($user),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $card = 'rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Career') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <x-section-nav :label="__('Career')" :active="$tab" :groups="['' => [
                'overview' => ['🧭', __('Career path')], 'trainings' => ['📜', __('Trainings & certificates')], 'contracts' => ['📄', __('Contracts & info')], 'cv' => ['🪪', __('CV')], 'applications' => ['📨', __('Applications')],
            ]]" />

            @foreach ($hints as $hint)
                <p @class(['rounded-xl px-4 py-2.5 text-sm', 'bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100' => $hint['level'] === 'warning', 'bg-sky-50 text-sky-900 dark:bg-sky-900/30 dark:text-sky-100' => $hint['level'] !== 'warning'])>{{ $hint['icon'] }} {{ $hint['text'] }}</p>
            @endforeach

            @if ($tab === 'overview')
                <div class="flex flex-wrap gap-2">
                    @foreach (['job', 'education', 'training', 'milestone'] as $type)
                        <button type="button" wire:click="add('{{ $type }}')" class="rounded-full bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-xs hover:bg-indigo-500">+ {{ $types[$type]['icon'] }} {{ $types[$type]['label'] }}</button>
                    @endforeach
                </div>
                <section class="{{ $card }}">
                    @if ($timeline->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Add your jobs, school and studies, trainings and milestones – they form your career path and your CV.') }}</p>
                    @else
                        <ol class="relative ms-3 border-s border-gray-200 dark:border-gray-700">
                            @foreach ($timeline as $item)
                                <li wire:key="timeline-{{ $item->id }}" class="mb-5 ms-6">
                                    <span class="absolute -start-3 flex h-6 w-6 items-center justify-center rounded-full bg-white text-sm ring-4 ring-white dark:bg-gray-800 dark:ring-gray-800" aria-hidden="true">{{ $types[$item->type]['icon'] }}</span>
                                    <button type="button" wire:click="edit({{ $item->id }})" class="block w-full text-start">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $item->period() }}@if ($item->isCurrent()) · <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ __('current') }}</span>@endif</span>
                                        <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $item->title }}</span>
                                        @if ($item->organization || $item->location)
                                            <span class="block text-sm text-gray-600 dark:text-gray-300">{{ collect([$item->organization, $item->location])->filter()->implode(' · ') }}</span>
                                        @endif
                                        @if ($item->description)
                                            <span class="mt-1 block whitespace-pre-line text-sm text-gray-600 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($item->description, 220) }}</span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            @elseif ($tab === 'trainings' || $tab === 'contracts')
                @php($listType = $tab === 'trainings' ? 'training' : 'contract')
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $tab === 'trainings' ? __('Trainings you attended – keep the certificate with each one (paperless or upload).') : __('Work contracts and important information such as salary, notice period and holidays – with the contract documents.') }}</p>
                    <button type="button" wire:click="add('{{ $listType }}')" class="rounded-full bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-xs hover:bg-indigo-500">+ {{ $types[$listType]['label'] }}</button>
                </div>
                <ul class="space-y-3">
                    @forelse ($entries->where('type', $listType)->sortByDesc(fn ($item) => $item->starts_on?->toDateString() ?? '') as $item)
                        <li wire:key="entry-{{ $item->id }}" class="{{ $card }}">
                            <div class="flex flex-wrap items-start gap-3">
                                <button type="button" wire:click="edit({{ $item->id }})" class="min-w-0 flex-1 text-start">
                                    <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $types[$item->type]['icon'] }} {{ $item->title }}</span>
                                    <span class="block text-sm text-gray-600 dark:text-gray-300">{{ collect([$item->organization, $item->period()])->filter()->implode(' · ') }}</span>
                                    @if ($listType === 'training')
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            @if ($item->detail('hours')){{ __(':hours hours', ['hours' => $item->detail('hours')]) }}@endif
                                            @if ($item->expiresOn()) · <span @class(['text-red-600 dark:text-red-400' => $item->expiresOn()->isPast()])>{{ __('valid until :date', ['date' => $item->expiresOn()->format('d.m.Y')]) }}</span>@endif
                                        </span>
                                    @else
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ collect([$item->detail('salary') ? __('Salary: :value', ['value' => $item->detail('salary')]) : null, $item->detail('weekly_hours') ? __(':hours h/week', ['hours' => $item->detail('weekly_hours')]) : null, $item->detail('vacation_days') ? __(':days days holiday', ['days' => $item->detail('vacation_days')]) : null, $item->detail('notice_period') ? __('Notice: :value', ['value' => $item->detail('notice_period')]) : null])->filter()->implode(' · ') }}</span>
                                    @endif
                                </button>
                                <button type="button" wire:click="toggleDocuments({{ $item->id }})" @class(['shrink-0 rounded-lg px-2.5 py-1 text-sm ring-1', 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-900/40 dark:text-indigo-200 dark:ring-indigo-800' => $documentsFor === $item->id, 'text-gray-600 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => $documentsFor !== $item->id]) aria-expanded="{{ $documentsFor === $item->id ? 'true' : 'false' }}">🗂 {{ $listType === 'training' ? __('Certificate') : __('Documents') }} ({{ $documentCounts[$item->id] ?? 0 }})</button>
                            </div>
                            @if ($documentsFor === $item->id)
                                <div class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
                                    <livewire:documents-panel type="career_entry" :id="$item->id" :wire:key="'documents-career-'.$item->id" />
                                </div>
                            @endif
                        </li>
                    @empty
                        <li class="{{ $card }} text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing here yet.') }}</li>
                    @endforelse
                </ul>
            @elseif ($tab === 'cv')
                <form wire:submit="saveProfile" class="{{ $card }} grid gap-3 sm:grid-cols-2">
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Headline') }}<input type="text" wire:model="profile.headline" class="{{ $input }}" placeholder="{{ __('e.g. Software developer with 8 years of experience') }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Profile') }}<textarea wire:model="profile.summary" rows="4" class="{{ $input }}" placeholder="{{ __('A few sentences about you, your strengths and what you are looking for') }}"></textarea></label>
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Skills') }}<textarea wire:model="profile.skills" rows="3" class="{{ $input }}" placeholder="{{ __('Separated by commas') }}"></textarea></label>
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Languages') }}<textarea wire:model="profile.languages" rows="3" class="{{ $input }}" placeholder="{{ __('e.g. German (native), English (C1)') }}"></textarea></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Contact details on the CV') }}<textarea wire:model="profile.contact" rows="2" class="{{ $input }}" placeholder="{{ __('Address, phone, e-mail') }}"></textarea></label>
                    <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('career.cv') }}" target="_blank" class="rounded-lg px-3 py-2 text-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700">📄 {{ __('CV as PDF') }}</a>
                    </div>
                </form>

                <section class="{{ $card }}">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ Auth::user()->name }}</h3>
                    @if ($profile['headline'])<p class="text-sm text-indigo-700 dark:text-indigo-300">{{ $profile['headline'] }}</p>@endif
                    @if ($profile['summary'])<p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $profile['summary'] }}</p>@endif
                    @foreach (['job' => __('Work experience'), 'education' => __('Education'), 'training' => __('Further training')] as $type => $heading)
                        @php($items = $timeline->where('type', $type))
                        @if ($items->isNotEmpty())
                            <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $heading }}</h4>
                            <ul class="mt-1 space-y-1.5 text-sm">
                                @foreach ($items as $item)
                                    <li class="flex gap-3"><span class="w-32 shrink-0 text-gray-500 dark:text-gray-400">{{ $item->period() }}</span><span class="text-gray-900 dark:text-gray-100">{{ $item->title }}@if ($item->organization)<span class="text-gray-600 dark:text-gray-300"> · {{ $item->organization }}</span>@endif</span></li>
                                @endforeach
                            </ul>
                        @endif
                    @endforeach
                    @if ($skills !== [])
                        <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Skills') }}</h4>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            @foreach ($skills as $skill)<span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ $skill }}</span>@endforeach
                        </div>
                    @endif
                </section>
            @elseif ($tab === 'applications')
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Keep track of your applications – with documents, contact and status.') }}</p>
                    <button type="button" wire:click="add('application')" class="rounded-full bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-xs hover:bg-indigo-500">+ {{ __('Application') }}</button>
                </div>
                <ul class="space-y-3">
                    @forelse ($entries->where('type', 'application')->sortBy(fn ($item) => array_search($item->detail('status') ?? 'planned', \App\Models\CareerEntry::APPLICATION_STATUSES)) as $item)
                        @php($status = $item->detail('status') ?? 'planned')
                        <li wire:key="application-{{ $item->id }}" class="{{ $card }}">
                            <div class="flex flex-wrap items-start gap-3">
                                <button type="button" wire:click="edit({{ $item->id }})" class="min-w-0 flex-1 text-start">
                                    <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $item->title }}</span>
                                    <span class="block text-sm text-gray-600 dark:text-gray-300">{{ collect([$item->organization, $item->location, $item->starts_on?->format('d.m.Y')])->filter()->implode(' · ') }}</span>
                                </button>
                                <select wire:change="setStatus({{ $item->id }}, $event.target.value)" aria-label="{{ __('Status') }}" class="rounded-lg border-0 py-1 text-xs font-medium {{ $statuses[$status]['color'] }}">
                                    @foreach ($statuses as $key => $statusOption)
                                        <option value="{{ $key }}" @selected($status === $key)>{{ $statusOption['label'] }}</option>
                                    @endforeach
                                </select>
                                @if ($item->detail('url'))
                                    <a href="{{ $item->detail('url') }}" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Job ad') }} ↗</a>
                                @endif
                                <button type="button" wire:click="toggleDocuments({{ $item->id }})" class="text-sm text-gray-500 hover:text-indigo-600 dark:text-gray-400">🗂 {{ $documentCounts[$item->id] ?? 0 }}</button>
                            </div>
                            @if ($documentsFor === $item->id)
                                <div class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
                                    <livewire:documents-panel type="career_entry" :id="$item->id" :wire:key="'documents-career-'.$item->id" />
                                </div>
                            @endif
                        </li>
                    @empty
                        <li class="{{ $card }} text-sm text-gray-500 dark:text-gray-400">{{ __('No applications yet.') }}</li>
                    @endforelse
                </ul>

                <section class="{{ $card }}">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">💡 {{ __('Suggestions for applications') }}</h3>
                    <form wire:submit="saveProfile" class="mt-2 flex flex-wrap items-end gap-2">
                        <label class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ __('Wished-for job') }}<input type="text" wire:model="profile.desired_role" class="{{ $input }}" placeholder="{{ $terms['what'] ?: __('e.g. Project manager') }}"></label>
                        <label class="w-48 text-sm text-gray-700 dark:text-gray-300">{{ __('Place') }}<input type="text" wire:model="profile.desired_location" class="{{ $input }}" placeholder="{{ $terms['where'] ?: __('e.g. Munich') }}"></label>
                        <x-secondary-button type="submit">{{ __('Save') }}</x-secondary-button>
                    </form>
                    @if ($terms['what'] === '')
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Enter a wished-for job or add your current job to get suggestions.') }}</p>
                    @else
                        <div class="mt-3 flex flex-wrap gap-2 text-sm">
                            @foreach ($searchLinks as $link)
                                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-full px-3 py-1 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700">{{ $link['label'] }} ↗</a>
                            @endforeach
                        </div>
                        @if ($jobs === null)
                            <button type="button" wire:click="$set('loadJobs', true)" class="mt-3 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">🔎 {{ __('Show current job offers for “:what”', ['what' => $terms['what']]) }}</button>
                        @elseif ($jobs === [])
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No offers found right now – try the links above.') }}</p>
                        @else
                            <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($jobs as $job)
                                    <li wire:key="job-{{ md5($job['url']) }}" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                                        <a href="{{ $job['url'] }}" target="_blank" rel="noopener noreferrer" class="min-w-0 flex-1">
                                            <span class="block font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $job['title'] }}</span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ collect([$job['employer'], $job['location'], $job['published'] ? \Illuminate\Support\Carbon::parse($job['published'])->format('d.m.Y') : null])->filter()->implode(' · ') }}</span>
                                        </a>
                                        <button type="button" wire:click="rememberJob(@js(['title' => $job['title'], 'employer' => $job['employer'], 'location' => $job['location'], 'url' => $job['url']]))" class="shrink-0 rounded-lg px-2.5 py-1 text-xs text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800">+ {{ __('Remember') }}</button>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-xs text-gray-400">{{ __('Source: job exchange of the Federal Employment Agency') }}</p>
                        @endif
                    @endif
                </section>
            @endif
        </div>
    </div>

    @if ($sheet)
        <x-sheet close="closeSheet" :label="$types[$entry['type']]['label']" size="md" wire:key="career-sheet-{{ $editingId ?? 'new' }}">
            <x-slot:header>
                <span class="truncate">{{ $types[$entry['type']]['icon'] }} {{ $types[$entry['type']]['label'] }}</span>
            </x-slot:header>
            <form wire:submit="save" id="career-form" class="space-y-3">
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ ['job' => __('Position'), 'education' => __('Degree or school'), 'training' => __('Training'), 'milestone' => __('Milestone'), 'contract' => __('Contract'), 'application' => __('Position')][$entry['type']] }}
                    <input type="text" wire:model="entry.title" class="{{ $input }}" autofocus><x-input-error :messages="$errors->get('entry.title')" />
                </label>
                @if ($entry['type'] !== 'milestone')
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ ['job' => __('Employer'), 'education' => __('School / university'), 'training' => __('Provider'), 'contract' => __('Employer'), 'application' => __('Company')][$entry['type']] }}<input type="text" wire:model="entry.organization" class="{{ $input }}"></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Place') }}<input type="text" wire:model="entry.location" class="{{ $input }}"></label>
                    </div>
                @endif
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ in_array($entry['type'], ['training', 'milestone'], true) ? __('Date') : ($entry['type'] === 'application' ? __('Applied on') : __('From')) }}<input type="date" wire:model="entry.starts_on" class="{{ $input }}"></label>
                    @if (! in_array($entry['type'], ['milestone', 'application'], true))
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ $entry['type'] === 'training' ? __('Until (optional)') : __('Until (empty = today)') }}<input type="date" wire:model="entry.ends_on" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.ends_on')" /></label>
                    @endif
                </div>
                @if ($entry['type'] === 'education')
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Grade') }}<input type="text" wire:model="entry.grade" class="{{ $input }}"></label>
                @elseif ($entry['type'] === 'training')
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Hours') }}<input type="text" inputmode="decimal" wire:model="entry.hours" class="{{ $input }}"></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Certificate valid until') }}<input type="date" wire:model="entry.expires_on" class="{{ $input }}"></label>
                    </div>
                @elseif ($entry['type'] === 'contract')
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Salary') }}<input type="text" wire:model="entry.salary" class="{{ $input }}" placeholder="{{ __('e.g. 4,200 € gross/month') }}"></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Hours per week') }}<input type="text" inputmode="decimal" wire:model="entry.weekly_hours" class="{{ $input }}"></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Holiday days') }}<input type="text" inputmode="decimal" wire:model="entry.vacation_days" class="{{ $input }}"></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Notice period') }}<input type="text" wire:model="entry.notice_period" class="{{ $input }}" placeholder="{{ __('e.g. 3 months to the end of the month') }}"></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Probation until') }}<input type="date" wire:model="entry.probation_until" class="{{ $input }}"></label>
                    </div>
                @elseif ($entry['type'] === 'application')
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Status') }}
                            <select wire:model="entry.status" class="{{ $input }}">
                                @foreach ($statuses as $key => $statusOption)
                                    <option value="{{ $key }}">{{ $statusOption['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Contact person') }}<input type="text" wire:model="entry.contact" class="{{ $input }}"></label>
                    </div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Link to the job ad') }}<input type="url" wire:model="entry.url" class="{{ $input }}" placeholder="https://…"><x-input-error :messages="$errors->get('entry.url')" /></label>
                @endif
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ $entry['type'] === 'job' ? __('Tasks & achievements') : __('Notes') }}<textarea wire:model="entry.description" rows="4" class="{{ $input }}"></textarea></label>
            </form>
            <x-slot:footer>
                <div class="flex items-center gap-2">
                    <x-primary-button form="career-form">{{ __('Save') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeSheet">{{ __('Cancel') }}</x-secondary-button>
                    @if ($editingId)
                        <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this entry?') }}" class="ms-auto text-sm text-red-600 hover:underline dark:text-red-400">{{ __('Delete') }}</button>
                    @endif
                </div>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
