<?php

use App\Models\HealthEntry;
use App\Models\HealthProfile;
use App\Support\Life\HealthPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Health of the family: an emergency card per person, check-ups and vaccinations by age,
 * medications with reminder times and doctor's appointments – private and encrypted.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Url(as: 'profile')]
    public ?int $profileId = null;

    /** card, prevention, medications or appointments */
    #[Url(except: 'card')]
    public string $tab = 'card';

    public bool $showProfileForm = false;

    public bool $isCreating = false;

    /**
     * @var array<string, string>
     */
    public array $person = [];

    /**
     * @var array{kind: string, title: string, done_on: string, next_due_on: string, interval_months: string, time_of_day: string, times: string, dosage: string, notes: string}
     */
    public array $entry = [];

    public function mount(): void
    {
        if (! in_array($this->tab, ['card', 'prevention', 'medications', 'appointments'], true)) {
            $this->tab = 'card';
        }

        $profiles = HealthProfile::where('user_id', Auth::id())->orderBy('id')->get();

        if (! $profiles->contains('id', $this->profileId)) {
            $this->profileId = $profiles->first()?->id;
        }

        if ($this->profileId === null) {
            $this->newProfile();
            $this->isCreating = true;
        } else {
            $this->fillPerson();
        }

        $this->resetEntry();
    }

    private function profile(): HealthProfile
    {
        return HealthProfile::where('user_id', Auth::id())->find($this->profileId) ?? abort(404);
    }

    public function selectProfile(int $id): void
    {
        $this->profileId = HealthProfile::where('user_id', Auth::id())->find($id)?->id ?? abort(404);
        $this->showProfileForm = false;
        $this->isCreating = false;
        $this->fillPerson();
        $this->resetEntry();
    }

    public function cancelProfile(): void
    {
        $this->showProfileForm = false;
        $this->isCreating = false;
        $this->fillPerson();
    }

    public function newProfile(): void
    {
        $this->person = ['name' => HealthProfile::where('user_id', Auth::id())->exists() ? '' : Auth::user()->name, 'birth_date' => '', 'sex' => '', 'blood_type' => '', 'allergies' => '', 'conditions' => '', 'insurance' => '', 'emergency_contact' => '', 'doctors' => ''];
        $this->showProfileForm = true;
        $this->resetValidation();
    }

    private function fillPerson(): void
    {
        $profile = $this->profile();
        $this->person = [
            'name' => $profile->name,
            'birth_date' => (string) $profile->birth_date?->toDateString(),
            'sex' => (string) $profile->sex,
            'blood_type' => (string) $profile->blood_type,
            'allergies' => (string) $profile->allergies,
            'conditions' => (string) $profile->conditions,
            'insurance' => (string) $profile->insurance,
            'emergency_contact' => (string) $profile->emergency_contact,
            'doctors' => (string) $profile->doctors,
        ];
    }

    public function saveProfile(): void
    {
        $data = $this->validate([
            'person.name' => ['required', 'string', 'max:120'],
            'person.birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'person.sex' => ['nullable', Rule::in(['f', 'm', 'd'])],
            'person.blood_type' => ['nullable', Rule::in(HealthProfile::BLOOD_TYPES)],
            'person.allergies' => ['nullable', 'string', 'max:2000'],
            'person.conditions' => ['nullable', 'string', 'max:2000'],
            'person.insurance' => ['nullable', 'string', 'max:500'],
            'person.emergency_contact' => ['nullable', 'string', 'max:500'],
            'person.doctors' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['person.name' => __('Name')])['person'];

        $attributes = collect($data)->map(fn ($value) => $value === '' ? null : $value)->all();

        if ($this->isCreating || $this->profileId === null) {
            $this->profileId = HealthProfile::create($attributes + ['user_id' => Auth::id()])->id;
        } else {
            $this->profile()->update($attributes);
        }

        $this->showProfileForm = false;
        $this->isCreating = false;
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function startNewProfile(): void
    {
        $this->newProfile();
        $this->isCreating = true;
    }

    public function editProfile(): void
    {
        $this->fillPerson();
        $this->isCreating = false;
        $this->showProfileForm = true;
    }

    public function deleteProfile(): void
    {
        $this->profile()->delete();
        $this->profileId = HealthProfile::where('user_id', Auth::id())->orderBy('id')->value('id');
        $this->isCreating = $this->profileId === null;

        if ($this->isCreating) {
            $this->newProfile();
        } else {
            $this->fillPerson();
        }
    }

    private function resetEntry(?string $kind = null): void
    {
        $kind ??= match ($this->tab) {
            'medications' => HealthEntry::MEDICATION,
            'appointments' => HealthEntry::APPOINTMENT,
            default => HealthEntry::CHECKUP,
        };
        $this->entry = ['kind' => $kind, 'title' => '', 'done_on' => '', 'next_due_on' => '', 'interval_months' => '', 'time_of_day' => '', 'times' => '', 'dosage' => '', 'notes' => ''];
        $this->resetValidation();
    }

    public function updatedTab(): void
    {
        $this->resetEntry();
    }

    public function addEntry(): void
    {
        $kind = $this->entry['kind'];
        $data = $this->validate([
            'entry.kind' => ['required', Rule::in([HealthEntry::CHECKUP, HealthEntry::VACCINATION, HealthEntry::APPOINTMENT, HealthEntry::MEDICATION])],
            'entry.title' => ['required', 'string', 'max:255'],
            'entry.done_on' => ['nullable', 'date'],
            'entry.next_due_on' => [$kind === HealthEntry::APPOINTMENT ? 'required' : 'nullable', 'date'],
            'entry.interval_months' => ['nullable', 'integer', 'between:1,240'],
            'entry.time_of_day' => ['nullable', 'date_format:H:i'],
            'entry.times' => ['nullable', 'string', 'max:100', 'regex:/^\s*([01]\d|2[0-3]):[0-5]\d(\s*,\s*([01]\d|2[0-3]):[0-5]\d)*\s*$/'],
            'entry.dosage' => ['nullable', 'string', 'max:255'],
            'entry.notes' => ['nullable', 'string', 'max:2000'],
        ], ['entry.times.regex' => __('Enter times like 08:00, 20:00.')], ['entry.title' => __('Title'), 'entry.next_due_on' => __('Date')])['entry'];

        $times = $data['times'] ? array_values(array_unique(array_map('trim', explode(',', $data['times'])))) : null;
        $next = $data['next_due_on'] ?: ($data['done_on'] && $data['interval_months'] ? Carbon::parse($data['done_on'])->addMonthsNoOverflow((int) $data['interval_months'])->toDateString() : null);

        $this->profile()->entries()->create([
            'kind' => $kind,
            'title' => $data['title'],
            'done_on' => $data['done_on'] ?: null,
            'next_due_on' => $next,
            'interval_months' => $data['interval_months'] ?: null,
            'time_of_day' => $data['time_of_day'] ?: null,
            'times' => $kind === HealthEntry::MEDICATION ? $times : null,
            'dosage' => $data['dosage'] ?: null,
            'notes' => $data['notes'] ?: null,
        ]);

        $this->resetEntry($kind);
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function adopt(string $key): void
    {
        $suggestion = collect(HealthPlan::suggestions($this->profile()))->firstWhere('key', $key) ?? abort(404);

        $this->profile()->entries()->create(['kind' => $suggestion['kind'], 'key' => $key, 'title' => $suggestion['title'], 'next_due_on' => $suggestion['due_on'], 'interval_months' => $suggestion['interval'], 'notes' => $suggestion['hint']]);
    }

    public function done(int $id): void
    {
        $entry = $this->findEntry($id);
        $entry->markDone();
        $this->dispatch('toast', message: $entry->next_due_on ? __('Done – next time on :date.', ['date' => $entry->next_due_on->format('d.m.Y')]) : __('Done.'));
    }

    public function toggleActive(int $id): void
    {
        $entry = $this->findEntry($id);
        $entry->update(['active' => ! $entry->active]);
    }

    public function deleteEntry(int $id): void
    {
        $this->findEntry($id)->delete();
    }

    private function findEntry(int $id): HealthEntry
    {
        return $this->profile()->entries()->find($id) ?? abort(404);
    }

    public function with(): array
    {
        $profiles = HealthProfile::where('user_id', Auth::id())->orderBy('id')->get();
        $profile = $profiles->firstWhere('id', $this->profileId);

        return [
            'profiles' => $profiles,
            'profile' => $profile,
            'entries' => $profile ? $profile->entries()->get() : collect(),
            'suggestions' => $profile && $this->tab === 'prevention' ? HealthPlan::suggestions($profile) : [],
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $tabClass = 'flex-1 shrink-0 whitespace-nowrap rounded-lg px-2 py-2 text-xs font-medium transition pointer-coarse:min-h-11 sm:px-3 sm:text-sm';
    $sexes = ['f' => __('female'), 'm' => __('male'), 'd' => __('diverse')];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Health') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" aria-label="{{ __('People') }}">
                @foreach ($profiles as $item)
                    <button type="button" wire:click="selectProfile({{ $item->id }})" @class(['shrink-0 rounded-full px-3 py-1.5 text-sm pointer-coarse:min-h-11', 'bg-indigo-600 text-white' => $item->id === $profileId && ! $isCreating, 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => $item->id !== $profileId || $isCreating])>{{ $item->name }}@if ($item->age() !== null) <span class="opacity-70">· {{ $item->age() }}</span>@endif</button>
                @endforeach
                <button type="button" wire:click="startNewProfile" class="shrink-0 rounded-full px-3 py-1.5 text-sm text-indigo-600 ring-1 ring-indigo-200 hover:bg-indigo-50 pointer-coarse:min-h-11 dark:text-indigo-300 dark:ring-indigo-800">+ {{ __('Person') }}</button>
            </nav>

            @if ($showProfileForm || ! $profile)
                <form wire:submit="saveProfile" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 sm:col-span-6 dark:text-gray-100">{{ $isCreating || ! $profile ? __('New person') : __('Edit emergency card') }}</h3>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="person.name" class="{{ $input }}"><x-input-error :messages="$errors->get('person.name')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Date of birth') }}<input type="date" wire:model="person.birth_date" class="{{ $input }}"><x-input-error :messages="$errors->get('person.birth_date')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Sex') }}
                        <select wire:model="person.sex" class="{{ $input }}"><option value="">–</option>@foreach ($sexes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Blood type') }}
                        <select wire:model="person.blood_type" class="{{ $input }}"><option value="">–</option>@foreach (\App\Models\HealthProfile::BLOOD_TYPES as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Allergies & intolerances') }}<textarea wire:model="person.allergies" rows="2" class="{{ $input }}"></textarea></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Pre-existing conditions') }}<textarea wire:model="person.conditions" rows="2" class="{{ $input }}"></textarea></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Health insurance & number') }}<input type="text" wire:model="person.insurance" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Emergency contact') }}<input type="text" wire:model="person.emergency_contact" class="{{ $input }}" placeholder="{{ __('Name, relation, phone') }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Doctors (one per line)') }}<textarea wire:model="person.doctors" rows="3" class="{{ $input }}" placeholder="{{ __('e.g. Family doctor Dr. Meier, 089 123456') }}"></textarea></label>
                    <div class="flex items-center gap-2 sm:col-span-6">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        @if ($profile)
                            <x-secondary-button type="button" wire:click="cancelProfile">{{ __('Cancel') }}</x-secondary-button>
                        @endif
                    </div>
                </form>
            @endif

            @if ($profile && ! $showProfileForm)
                <x-section-nav :label="__('Health')" :active="$tab" :groups="['' => ['card' => ['🆘', __('Emergency card')], 'prevention' => ['🩺', __('Prevention')], 'medications' => ['💊', __('Medication')], 'appointments' => ['📅', __('Appointments')]]]" />

                @if ($tab === 'card')
                    <section class="rounded-2xl bg-white p-5 shadow-xs ring-2 ring-rose-200 dark:bg-gray-800 dark:ring-rose-900">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-400">🆘 {{ __('Emergency card') }}</p>
                                <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $profile->name }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $profile->birth_date ? __('born :date', ['date' => $profile->birth_date->format('d.m.Y')]).' · '.__(':age years', ['age' => $profile->age()]) : '' }}</p>
                            </div>
                            <button type="button" wire:click="editProfile" class="text-gray-400 hover:text-indigo-600" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                        </div>
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            @foreach ([__('Blood type') => $profile->blood_type, __('Allergies & intolerances') => $profile->allergies, __('Pre-existing conditions') => $profile->conditions, __('Current medication') => $entries->where('kind', 'medication')->where('active', true)->map(fn ($medication) => trim($medication->title.' '.$medication->dosage))->implode(', '), __('Health insurance & number') => $profile->insurance, __('Emergency contact') => $profile->emergency_contact] as $label => $value)
                                <div>
                                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                    <dd class="whitespace-pre-line font-medium text-gray-900 dark:text-gray-100">{{ $value ?: '–' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @if ($profile->doctors)
                            <div class="mt-4 text-sm">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Doctors') }}</p>
                                <p class="whitespace-pre-line text-gray-900 dark:text-gray-100">{{ $profile->doctors }}</p>
                            </div>
                        @endif
                        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Stored encrypted, only visible to you – and in the emergency folder to your trusted people.') }}</p>
                    </section>
                    <button type="button" wire:click="deleteProfile" wire:confirm="{{ __('Delete this person with all health data?') }}" class="text-sm text-red-600 hover:underline">{{ __('Delete person') }}</button>
                @else
                    @if ($tab === 'prevention' && $suggestions !== [])
                        <section class="rounded-2xl bg-indigo-50 p-5 ring-1 ring-indigo-100 dark:bg-indigo-900/20 dark:ring-indigo-800">
                            <h3 class="font-semibold text-indigo-950 dark:text-indigo-100">💡 {{ __('Recommended for :name', ['name' => $profile->name]) }}</h3>
                            <ul class="mt-3 space-y-2 text-sm">
                                @foreach ($suggestions as $suggestion)
                                    <li wire:key="suggestion-{{ $suggestion['key'] }}" class="flex flex-wrap items-center justify-between gap-2">
                                        <span><span class="font-medium text-gray-900 dark:text-gray-100">{{ $suggestion['kind'] === 'vaccination' ? '💉' : '🩺' }} {{ $suggestion['title'] }}</span> <span class="text-gray-600 dark:text-gray-300">· {{ $suggestion['hint'] }}</span></span>
                                        <button type="button" wire:click="adopt('{{ $suggestion['key'] }}')" class="rounded-full bg-white px-3 py-1 text-xs font-medium text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-100 dark:bg-gray-800 dark:text-indigo-300 dark:ring-indigo-700">+ {{ __('Add to plan') }}</button>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-3 text-xs text-indigo-900 dark:text-indigo-200">{{ __('Simplified from the statutory health insurance and STIKO recommendations – your doctor knows what fits you.') }}</p>
                        </section>
                    @endif

                    @if ($tab === 'prevention')
                        <div class="flex justify-end">
                            <a href="{{ route('health.vaccinations', $profile) }}" class="rounded-lg px-3 py-1.5 text-sm text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">📄 {{ __('Vaccination record as PDF') }}</a>
                        </div>
                    @endif

                    @php
                        $kinds = match ($tab) {
                            'prevention' => ['checkup', 'vaccination'],
                            'medications' => ['medication'],
                            default => ['appointment'],
                        };
                        $shown = $entries->whereIn('kind', $kinds);
                    @endphp

                    <section class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($shown as $item)
                                <li wire:key="health-entry-{{ $item->id }}" @class(['flex items-center gap-3 px-5 py-3', 'opacity-60' => $item->kind === 'medication' && ! $item->active])>
                                    @if ($item->kind === 'medication')
                                        <button type="button" wire:click="toggleActive({{ $item->id }})" class="text-xl" title="{{ $item->active ? __('Pause') : __('Resume') }}">{{ $item->active ? '💊' : '⏸️' }}</button>
                                    @else
                                        <button type="button" wire:click="done({{ $item->id }})" class="rounded-full p-1 text-gray-400 hover:text-emerald-600 pointer-coarse:min-h-11 pointer-coarse:min-w-11" title="{{ __('Mark as done') }}" aria-label="{{ __('Mark as done') }}"><x-icon name="check-circle" class="h-6 w-6" /></button>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $item->kind === 'vaccination' ? '💉 ' : '' }}{{ $item->title }}@if ($item->dosage) <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $item->dosage }}</span>@endif</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            @if ($item->kind === 'medication')
                                                {{ $item->times ? __('at :times', ['times' => implode(', ', $item->times)]) : __('no fixed times') }}
                                            @else
                                                {{ $item->done_on ? __('last on :date', ['date' => $item->done_on->format('d.m.Y')]) : '' }}{{ $item->interval_months ? ' · '.trans_choice('every month|every :count months', $item->interval_months) : '' }}{{ $item->time_of_day ? ' · '.$item->time_of_day : '' }}
                                            @endif
                                            @if ($item->notes) · {{ $item->notes }}@endif
                                        </p>
                                    </div>
                                    @if ($item->next_due_on)
                                        <span @class(['shrink-0 rounded-full px-2 py-0.5 text-xs tabular-nums', 'bg-red-50 text-red-800 dark:bg-red-900/40 dark:text-red-200' => $item->isOverdue(), 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $item->isOverdue()])>{{ $item->next_due_on->format('d.m.Y') }}</span>
                                    @endif
                                    <button type="button" wire:click="deleteEntry({{ $item->id }})" wire:confirm="{{ __('Delete this entry?') }}" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                                </li>
                            @empty
                                <li class="px-5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing entered yet.') }}</li>
                            @endforelse
                        </ul>
                    </section>

                    <form wire:submit="addEntry" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                        @if ($tab === 'prevention')
                            <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Type') }}
                                <select wire:model="entry.kind" class="{{ $input }}"><option value="checkup">🩺 {{ __('Check-up') }}</option><option value="vaccination">💉 {{ __('Vaccination') }}</option></select>
                            </label>
                        @endif
                        <label class="text-sm text-gray-700 sm:col-span-{{ $tab === 'prevention' ? 4 : 3 }} dark:text-gray-300">{{ $tab === 'medications' ? __('Medication') : ($tab === 'appointments' ? __('Appointment') : __('Title')) }}<input type="text" wire:model="entry.title" class="{{ $input }}" placeholder="{{ $tab === 'appointments' ? __('e.g. Orthopaedist Dr. Braun') : '' }}"><x-input-error :messages="$errors->get('entry.title')" /></label>
                        @if ($tab === 'medications')
                            <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Dosage') }}<input type="text" wire:model="entry.dosage" class="{{ $input }}" placeholder="{{ __('e.g. 1 tablet 400 mg') }}"></label>
                            <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Reminder times') }}<input type="text" wire:model="entry.times" class="{{ $input }}" placeholder="08:00, 20:00"><x-input-error :messages="$errors->get('entry.times')" /></label>
                        @elseif ($tab === 'appointments')
                            <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Date') }}<input type="date" wire:model="entry.next_due_on" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.next_due_on')" /></label>
                            <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Time') }}<input type="time" wire:model="entry.time_of_day" class="{{ $input }}"></label>
                        @else
                            <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Last done') }}<input type="date" wire:model="entry.done_on" class="{{ $input }}"></label>
                            <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Every … months') }}<input type="number" min="1" max="240" wire:model="entry.interval_months" class="{{ $input }}"></label>
                            <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Next time') }}<input type="date" wire:model="entry.next_due_on" class="{{ $input }}"></label>
                        @endif
                        <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Note') }}<input type="text" wire:model="entry.notes" class="{{ $input }}"></label>
                        <div class="sm:col-span-6"><x-primary-button>{{ __('Add') }}</x-primary-button></div>
                    </form>
                @endif
            @endif
        </div>
    </div>
</div>
