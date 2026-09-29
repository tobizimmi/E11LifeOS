<?php

use App\Models\EmergencyEntry;
use App\Models\TrustedPerson;
use App\Notifications\EmergencyAccessUpdate;
use App\Notifications\TrustedPersonInvitation;
use App\Support\Life\EmergencyFolder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Emergency folder: documents, directives, contacts, digital life and wishes with a checklist per
 * section, an overview read from the modules and trusted people who get access after a waiting period.
 */
new #[Layout('layouts.app')] class extends Component
{
    /** overview, one of the sections or access */
    #[Url(except: 'overview')]
    public string $section = 'overview';

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array{section: string, kind: string, title: string, details: string, location: string, expires_on: string, remind_days: string}
     */
    public array $entry = ['section' => '', 'kind' => '', 'title' => '', 'details' => '', 'location' => '', 'expires_on' => '', 'remind_days' => '60'];

    /**
     * @var array{name: string, email: string, wait_days: string}
     */
    public array $person = ['name' => '', 'email' => '', 'wait_days' => '7'];

    public function mount(): void
    {
        if ($this->section !== 'overview' && $this->section !== 'access' && ! isset(EmergencyFolder::sections()[$this->section])) {
            $this->section = 'overview';
        }
    }

    public function add(string $section, string $kind = EmergencyEntry::CUSTOM): void
    {
        $sections = EmergencyFolder::sections();
        abort_unless(isset($sections[$section]) && ($kind === EmergencyEntry::CUSTOM || isset($sections[$section]['presets'][$kind])), 404);

        $this->resetValidation();
        $this->editingId = null;
        $this->entry = ['section' => $section, 'kind' => $kind, 'title' => $sections[$section]['presets'][$kind]['label'] ?? '', 'details' => '', 'location' => '', 'expires_on' => '', 'remind_days' => '60'];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $entry = $this->findEntry($id);
        $this->resetValidation();
        $this->editingId = $entry->id;
        $this->entry = [
            'section' => $entry->section,
            'kind' => $entry->kind,
            'title' => $entry->title,
            'details' => (string) $entry->details,
            'location' => (string) $entry->location,
            'expires_on' => (string) $entry->expires_on?->toDateString(),
            'remind_days' => (string) $entry->remind_days,
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'entry.title' => ['required', 'string', 'max:255'],
            'entry.details' => ['nullable', 'string', 'max:5000'],
            'entry.location' => ['nullable', 'string', 'max:1000'],
            'entry.expires_on' => ['nullable', 'date'],
            'entry.remind_days' => ['required', 'integer', 'between:1,365'],
        ], attributes: ['entry.title' => __('Title')])['entry'];

        $attributes = [
            'title' => $data['title'],
            'details' => $data['details'] ?: null,
            'location' => $data['location'] ?: null,
            'expires_on' => $data['expires_on'] ?: null,
            'remind_days' => (int) $data['remind_days'],
        ];

        if ($this->editingId) {
            $this->findEntry($this->editingId)->update($attributes);
        } else {
            EmergencyEntry::create($attributes + [
                'user_id' => Auth::id(),
                'section' => $this->entry['section'],
                'kind' => $this->entry['kind'],
                'position' => (int) EmergencyEntry::where('user_id', Auth::id())->max('position') + 1,
            ]);
        }

        $this->showForm = false;
        $this->editingId = null;
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function delete(int $id): void
    {
        $this->findEntry($id)->delete();
        $this->showForm = false;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
    }

    public function addPerson(): void
    {
        $data = $this->validate([
            'person.name' => ['required', 'string', 'max:120'],
            'person.email' => ['required', 'email', 'max:255'],
            'person.wait_days' => ['required', 'integer', 'between:1,60'],
        ], attributes: ['person.name' => __('Name'), 'person.email' => __('Email')])['person'];

        $person = new TrustedPerson(['user_id' => Auth::id(), 'name' => $data['name'], 'email' => mb_strtolower($data['email']), 'wait_days' => (int) $data['wait_days']]);
        $this->invite($person);

        $this->person = ['name' => '', 'email' => '', 'wait_days' => '7'];
        $this->dispatch('toast', message: __('Invitation sent to :email.', ['email' => $person->email]));
    }

    public function resendInvitation(int $id): void
    {
        $this->invite($this->findPerson($id));
        $this->dispatch('toast', message: __('A new link was sent – the old one no longer works.'));
    }

    public function allowNow(int $id): void
    {
        $person = $this->findPerson($id);
        $person->update(['requested_at' => $person->requested_at ?? now(), 'granted_at' => now()]);
        Notification::route('mail', $person->email)->notify(new EmergencyAccessUpdate(EmergencyAccessUpdate::GRANTED, Auth::user()->name, $person->name));
    }

    public function decline(int $id): void
    {
        $person = $this->findPerson($id);
        $wasOpen = $person->requested_at !== null;
        $person->update(['requested_at' => null, 'granted_at' => null]);

        if ($wasOpen) {
            Notification::route('mail', $person->email)->notify(new EmergencyAccessUpdate(EmergencyAccessUpdate::DECLINED, Auth::user()->name, $person->name));
        }

        $this->dispatch('toast', message: __('Access closed.'));
    }

    public function removePerson(int $id): void
    {
        $this->findPerson($id)->delete();
    }

    private function invite(TrustedPerson $person): void
    {
        $token = $person->issueToken();
        $person->invited_at = now();
        $person->save();

        Notification::route('mail', $person->email)->notify(new TrustedPersonInvitation(Auth::user()->name, $person->name, route('emergency.access', ['token' => $token]), $person->wait_days));
    }

    private function findEntry(int $id): EmergencyEntry
    {
        return EmergencyEntry::where('user_id', Auth::id())->find($id) ?? abort(404);
    }

    private function findPerson(int $id): TrustedPerson
    {
        return TrustedPerson::where('user_id', Auth::id())->find($id) ?? abort(404);
    }

    public function with(): array
    {
        $entries = EmergencyEntry::where('user_id', Auth::id())->orderBy('position')->orderBy('id')->get();

        return [
            'sections' => EmergencyFolder::sections(),
            'entries' => $entries,
            'progress' => EmergencyFolder::progress($entries),
            'overview' => $this->section === 'overview' ? EmergencyFolder::overview(Auth::user()) : [],
            'expiring' => $entries->filter(fn (EmergencyEntry $entry) => $entry->isExpired() || $entry->expiresSoon())->sortBy('expires_on')->values(),
            'people' => TrustedPerson::where('user_id', Auth::id())->orderBy('name')->get(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $chip = 'shrink-0 rounded-full px-3 py-1.5 text-sm pointer-coarse:min-h-11';
    $chipOn = 'bg-indigo-600 text-white';
    $chipOff = 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🗂️ {{ __('Emergency folder') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" aria-label="{{ __('Sections') }}">
                <button type="button" wire:click="$set('section', 'overview')" class="{{ $chip }} {{ $section === 'overview' ? $chipOn : $chipOff }}">🧭 {{ __('Overview') }}</button>
                @foreach ($sections as $key => $info)
                    <button type="button" wire:click="$set('section', '{{ $key }}')" class="{{ $chip }} {{ $section === $key ? $chipOn : $chipOff }}">{{ $info['icon'] }} {{ $info['label'] }} <span class="opacity-70">{{ $progress['sections'][$key]['done'] }}/{{ $progress['sections'][$key]['total'] }}</span></button>
                @endforeach
                <button type="button" wire:click="$set('section', 'access')" class="{{ $chip }} {{ $section === 'access' ? $chipOn : $chipOff }}">🔐 {{ __('Trusted people') }}</button>
            </nav>

            @if ($section === 'overview')
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Filled in') }}</p>
                            <p class="mt-1 text-4xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $progress['percent'] }} %</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __(':done of :total checklist points', ['done' => $progress['done'], 'total' => $progress['total']]) }}</p>
                        </div>
                        <a href="{{ route('emergency.pdf') }}" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">🖨️ {{ __('PDF to print') }}</a>
                    </div>
                    <div class="mt-4 h-3 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}" aria-label="{{ __('Filled in') }}">
                        <div class="h-full rounded-full bg-emerald-600 dark:bg-emerald-500" style="width: {{ $progress['percent'] }}%"></div>
                    </div>
                    <ul class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                        @foreach ($sections as $key => $info)
                            <li>
                                <button type="button" wire:click="$set('section', '{{ $key }}')" class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-gray-700/50">
                                    <span class="text-gray-800 dark:text-gray-100">{{ $info['icon'] }} {{ $info['label'] }}</span>
                                    <span @class(['tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $progress['sections'][$key]['done'] === $progress['sections'][$key]['total'], 'text-gray-500 dark:text-gray-400' => $progress['sections'][$key]['done'] < $progress['sections'][$key]['total']])>{{ $progress['sections'][$key]['done'] }}/{{ $progress['sections'][$key]['total'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>

                @if ($expiring->isNotEmpty())
                    <section class="rounded-2xl bg-amber-50 p-5 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:ring-amber-800">
                        <h3 class="font-semibold text-amber-900 dark:text-amber-200">⏰ {{ __('Renew soon') }}</h3>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($expiring as $item)
                                <li wire:key="expiring-{{ $item->id }}">
                                    <button type="button" wire:click="edit({{ $item->id }})" class="text-left text-gray-900 hover:underline dark:text-gray-100">{{ $item->title }}</button>
                                    <span @class(['text-red-700 dark:text-red-300' => $item->isExpired(), 'text-amber-800 dark:text-amber-300' => ! $item->isExpired()])>· {{ $item->isExpired() ? __('expired on :date', ['date' => $item->expires_on->format('d.m.Y')]) : __('valid until :date', ['date' => $item->expires_on->format('d.m.Y')]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">📚 {{ __('From your modules') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Accounts, securities accounts, properties, loans and contracts are taken over automatically – your trusted people see them too.') }}</p>
                    @forelse ($overview as $group)
                        <div class="mt-4">
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $group['icon'] }} {{ $group['label'] }}</h4>
                            <ul class="mt-1 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                                @foreach ($group['rows'] as $row)
                                    <li class="flex flex-wrap justify-between gap-x-3 py-1.5">
                                        <span class="text-gray-800 dark:text-gray-100">{{ $row['label'] }}</span>
                                        @if ($row['detail'])
                                            <span class="text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing entered yet.') }}</p>
                    @endforelse
                </section>
            @elseif ($section === 'access')
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🔐 {{ __('Trusted people') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('A trusted person gets a personal link by e-mail. In an emergency they request access with it; you are informed by push and e-mail and can decline. Without an answer the folder opens for them after the waiting period – read only.') }}</p>

                    <ul class="mt-4 space-y-3">
                        @forelse ($people as $trusted)
                            <li wire:key="person-{{ $trusted->id }}" class="rounded-xl p-4 ring-1 ring-gray-200 dark:ring-gray-700">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $trusted->name }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $trusted->email }} · {{ trans_choice(':count day waiting period|:count days waiting period', $trusted->wait_days) }}</p>
                                    </div>
                                    @if ($trusted->hasAccess())
                                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">🔓 {{ __('has access') }}</span>
                                    @elseif ($trusted->isWaiting())
                                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">⚠ {{ __('requested – opens on :date', ['date' => $trusted->grantsOn()->format('d.m.Y H:i')]) }}</span>
                                    @else
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('invited') }}</span>
                                    @endif
                                </div>
                                @if ($trusted->last_viewed_at)
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Last opened :date', ['date' => $trusted->last_viewed_at->format('d.m.Y H:i')]) }}</p>
                                @endif
                                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                                    @if ($trusted->isWaiting())
                                        <x-danger-button type="button" wire:click="decline({{ $trusted->id }})">{{ __('Decline request') }}</x-danger-button>
                                        <x-secondary-button type="button" wire:click="allowNow({{ $trusted->id }})">{{ __('Allow now') }}</x-secondary-button>
                                    @elseif ($trusted->hasAccess())
                                        <x-secondary-button type="button" wire:click="decline({{ $trusted->id }})">{{ __('Withdraw access') }}</x-secondary-button>
                                    @endif
                                    <x-secondary-button type="button" wire:click="resendInvitation({{ $trusted->id }})">{{ __('Send new link') }}</x-secondary-button>
                                    <button type="button" wire:click="removePerson({{ $trusted->id }})" wire:confirm="{{ __('Remove this trusted person?') }}" class="ms-auto text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="h-5 w-5" /></button>
                                </div>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No trusted people yet.') }}</li>
                        @endforelse
                    </ul>

                    <form wire:submit="addPerson" class="mt-5 grid gap-3 border-t border-gray-100 pt-4 sm:grid-cols-6 dark:border-gray-700">
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="person.name" class="{{ $input }}"><x-input-error :messages="$errors->get('person.name')" /></label>
                        <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Email') }}<input type="email" wire:model="person.email" class="{{ $input }}"><x-input-error :messages="$errors->get('person.email')" /></label>
                        <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Waiting days') }}<input type="number" min="1" max="60" wire:model="person.wait_days" class="{{ $input }}"><x-input-error :messages="$errors->get('person.wait_days')" /></label>
                        <div class="sm:col-span-6"><x-primary-button>{{ __('Invite trusted person') }}</x-primary-button></div>
                    </form>
                </section>
            @else
                @php
                    $info = $sections[$section];
                    $sectionEntries = $entries->where('section', $section);
                @endphp
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $info['icon'] }} {{ $info['label'] }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $info['hint'] }}</p>

                    <ul class="mt-4 space-y-2">
                        @foreach ($info['presets'] as $kind => $preset)
                            @php
                                $filled = $sectionEntries->where('kind', $kind);
                            @endphp
                            @forelse ($filled as $item)
                                <li wire:key="entry-{{ $item->id }}" class="flex items-start gap-3 rounded-xl p-3 ring-1 ring-gray-200 dark:ring-gray-700">
                                    <span class="mt-0.5 text-emerald-600 dark:text-emerald-400" aria-hidden="true"><x-icon name="check-circle" /></span>
                                    <button type="button" wire:click="edit({{ $item->id }})" class="min-w-0 flex-1 text-left">
                                        <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $item->title }}</span>
                                        @if ($item->location)
                                            <span class="block text-sm text-gray-600 dark:text-gray-300">📍 {{ $item->location }}</span>
                                        @endif
                                        @if ($item->expires_on)
                                            <span @class(['block text-sm', 'text-red-700 dark:text-red-300' => $item->isExpired(), 'text-amber-800 dark:text-amber-300' => $item->expiresSoon(), 'text-gray-500 dark:text-gray-400' => ! $item->isExpired() && ! $item->expiresSoon()])>{{ $item->isExpired() ? __('expired on :date', ['date' => $item->expires_on->format('d.m.Y')]) : __('valid until :date', ['date' => $item->expires_on->format('d.m.Y')]) }}</span>
                                        @endif
                                    </button>
                                    <x-icon name="pencil" class="h-5 w-5 text-gray-400" />
                                </li>
                            @empty
                                <li wire:key="preset-{{ $kind }}">
                                    <button type="button" wire:click="add('{{ $section }}', '{{ $kind }}')" class="flex w-full items-start gap-3 rounded-xl border border-dashed border-gray-300 p-3 text-left hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/40">
                                        <span class="mt-0.5 text-gray-400" aria-hidden="true"><x-icon name="plus" /></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium text-gray-700 dark:text-gray-200">{{ $preset['label'] }}</span>
                                            @if ($preset['hint'])
                                                <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $preset['hint'] }}</span>
                                            @endif
                                        </span>
                                    </button>
                                </li>
                            @endforelse
                        @endforeach
                        @foreach ($sectionEntries->where('kind', \App\Models\EmergencyEntry::CUSTOM) as $item)
                            <li wire:key="entry-{{ $item->id }}" class="flex items-start gap-3 rounded-xl p-3 ring-1 ring-gray-200 dark:ring-gray-700">
                                <span class="mt-0.5 text-indigo-500" aria-hidden="true"><x-icon name="document-text" /></span>
                                <button type="button" wire:click="edit({{ $item->id }})" class="min-w-0 flex-1 text-left">
                                    <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $item->title }}</span>
                                    @if ($item->location)
                                        <span class="block text-sm text-gray-600 dark:text-gray-300">📍 {{ $item->location }}</span>
                                    @endif
                                </button>
                                <x-icon name="pencil" class="h-5 w-5 text-gray-400" />
                            </li>
                        @endforeach
                    </ul>
                    <button type="button" wire:click="add('{{ $section }}')" class="mt-3 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">+ {{ __('Own entry') }}</button>
                </section>
            @endif

            <p class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Details and storage places are stored encrypted and are only visible to you – and to your trusted people once their access is open.') }}</p>
        </div>
    </div>

    @if ($showForm)
        @php
            $presetInfo = $sections[$entry['section']]['presets'][$entry['kind']] ?? null;
        @endphp
        <x-sheet close="closeForm" :label="$entry['title'] ?: __('Own entry')" size="md">
            <x-slot name="header"><h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $sections[$entry['section']]['icon'] ?? '' }} {{ $editingId ? __('Edit entry') : __('New entry') }}</h3></x-slot>
            <form wire:submit="save" id="emergency-form" class="space-y-3 p-5">
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Title') }}<input type="text" wire:model="entry.title" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.title')" /></label>
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Details') }}<textarea wire:model="entry.details" rows="4" class="{{ $input }}" placeholder="{{ $presetInfo['hint'] ?? '' }}"></textarea><x-input-error :messages="$errors->get('entry.details')" /></label>
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Where is the original?') }}<input type="text" wire:model="entry.location" class="{{ $input }}" placeholder="{{ __('e.g. folder “Documents” in the study, safe deposit box') }}"></label>
                @if (($presetInfo['expires'] ?? false) || $entry['kind'] === \App\Models\EmergencyEntry::CUSTOM || $entry['expires_on'] !== '')
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Valid until') }}<input type="date" wire:model="entry.expires_on" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.expires_on')" /></label>
                        <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Remind days before') }}<input type="number" min="1" max="365" wire:model="entry.remind_days" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.remind_days')" /></label>
                    </div>
                @endif
                @if ($entry['section'] === 'digital')
                    <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">⚠ {{ __('Please do not enter passwords – only where they can be found.') }}</p>
                @endif
            </form>
            <x-slot name="footer">
                <div class="flex items-center gap-2">
                    <x-primary-button form="emergency-form">{{ __('Save') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeForm">{{ __('Cancel') }}</x-secondary-button>
                    @if ($editingId)
                        <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this entry?') }}" class="ms-auto text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="h-5 w-5" /></button>
                    @endif
                </div>
            </x-slot>
        </x-sheet>
    @endif
</div>
