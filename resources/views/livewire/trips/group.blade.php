<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Trip;
use App\Models\TripParticipant;
use App\Support\Life\GroupTrip;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Group trip / club outing: participants with personal links and replies, WhatsApp messages
 * (group and personal) and the password-protected trip folder for external people.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $tripId;

    public string $names = '';

    public ?int $editingId = null;

    /**
     * @var array{name: string, phone: string, email: string, personal_info: string, status: string}
     */
    public array $participant = ['name' => '', 'phone' => '', 'email' => '', 'personal_info' => '', 'status' => 'invited'];

    /**
     * @var array{meeting_point: string, organizer_contact: string, group_info: string, share_references: bool}
     */
    public array $folder = ['meeting_point' => '', 'organizer_contact' => '', 'group_info' => '', 'share_references' => false];

    public string $password = '';

    public function mount(Trip $trip): void
    {
        $this->resolveHousehold();
        $trip = $this->findTrip($trip->id);
        $this->tripId = $trip->id;
        $this->folder = ['meeting_point' => (string) $trip->meeting_point, 'organizer_contact' => (string) $trip->organizer_contact, 'group_info' => (string) $trip->group_info, 'share_references' => $trip->share_references];
    }

    private function findTrip(?int $id = null): Trip
    {
        return Trip::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($id ?? $this->tripId) ?? abort(404);
    }

    private function findParticipant(int $id): TripParticipant
    {
        return $this->findTrip()->participants()->find($id) ?? abort(404);
    }

    /**
     * Adds participants from a list of names (comma, semicolon or one per line).
     */
    public function addParticipants(): void
    {
        $this->validate(['names' => ['required', 'string', 'max:3000']], attributes: ['names' => __('Names')]);
        $trip = $this->findTrip();
        $existing = $trip->participants->map(fn (TripParticipant $participant) => mb_strtolower($participant->name))->all();
        $position = (int) $trip->participants()->max('position');

        collect(preg_split('/[,;\n]+/', $this->names))->map(fn ($name) => mb_substr(trim($name), 0, 100))->filter()
            ->reject(fn ($name) => in_array(mb_strtolower($name), $existing, true))->unique()->take(200)
            ->each(function (string $name) use ($trip, &$position) {
                $trip->participants()->create(['name' => $name, 'position' => ++$position, 'share_token' => Str::random(40)]);
            });

        $this->reset('names');
    }

    /**
     * Takes over the travellers entered on the trip (comma-separated names).
     */
    public function importTravelers(): void
    {
        $this->names = (string) $this->findTrip()->travelers;
        $this->addParticipants();
    }

    public function edit(int $id): void
    {
        $participant = $this->findParticipant($id);
        $this->editingId = $participant->id;
        $this->participant = ['name' => $participant->name, 'phone' => (string) $participant->phone, 'email' => (string) $participant->email, 'personal_info' => (string) $participant->personal_info, 'status' => $participant->status];
        $this->resetValidation();
    }

    public function saveParticipant(): void
    {
        $data = $this->validate([
            'participant.name' => ['required', 'string', 'max:100'],
            'participant.phone' => ['nullable', 'string', 'max:40'],
            'participant.email' => ['nullable', 'email', 'max:150'],
            'participant.personal_info' => ['nullable', 'string', 'max:2000'],
            'participant.status' => ['required', Rule::in(TripParticipant::STATUSES)],
        ], attributes: ['participant.name' => __('Name'), 'participant.email' => __('E-mail')])['participant'];

        $this->findParticipant((int) $this->editingId)->update([
            'name' => $data['name'],
            'phone' => trim((string) $data['phone']) ?: null,
            'email' => trim((string) $data['email']) ?: null,
            'personal_info' => trim((string) $data['personal_info']) ?: null,
            'status' => $data['status'],
        ]);
        $this->editingId = null;
    }

    public function removeParticipant(int $id): void
    {
        $this->findParticipant($id)->delete();
        $this->editingId = null;
    }

    /**
     * A new personal link; the old one stops working.
     */
    public function renewLink(int $id): void
    {
        $this->findParticipant($id)->update(['share_token' => Str::random(40)]);
    }

    public function saveFolder(): void
    {
        $data = $this->validate([
            'folder.meeting_point' => ['nullable', 'string', 'max:255'],
            'folder.organizer_contact' => ['nullable', 'string', 'max:150'],
            'folder.group_info' => ['nullable', 'string', 'max:5000'],
        ])['folder'];

        $this->findTrip()->update([
            'meeting_point' => trim((string) $data['meeting_point']) ?: null,
            'organizer_contact' => trim((string) $data['organizer_contact']) ?: null,
            'group_info' => trim((string) $data['group_info']) ?: null,
            'share_references' => (bool) $this->folder['share_references'],
        ]);
        $this->dispatch('toast', message: __('Saved.'));
    }

    /**
     * Turns the trip folder on with a password (at least 6 characters).
     */
    public function createFolderLink(): void
    {
        $this->validate(['password' => ['required', 'string', 'min:6', 'max:100']], attributes: ['password' => __('Password')]);
        $this->findTrip()->update(['share_token' => Str::random(40), 'share_password' => $this->password]);
        $this->dispatch('toast', message: __('Trip folder created – send the password separately from the link.'));
        $this->reset('password');
    }

    public function changePassword(): void
    {
        $this->validate(['password' => ['required', 'string', 'min:6', 'max:100']], attributes: ['password' => __('Password')]);
        $this->findTrip()->update(['share_password' => $this->password]);
        $this->dispatch('toast', message: __('Password changed.'));
        $this->reset('password');
    }

    public function revokeFolderLink(): void
    {
        $this->findTrip()->update(['share_token' => null, 'share_expires_at' => null, 'share_password' => null]);
    }

    public function setFolderExpiry(string $days = ''): void
    {
        abort_unless(in_array($days, ['', '7', '30', '90'], true), 422);
        $this->findTrip()->forceFill(['share_expires_at' => $days === '' ? null : now()->addDays((int) $days)])->save();
    }

    public function with(): array
    {
        $trip = $this->findTrip()->load(['participants', 'bookings']);
        $folderLink = $trip->share_token ? route('trips.folder', $trip->share_token) : null;
        $links = $trip->participants->mapWithKeys(fn (TripParticipant $participant) => [$participant->id => $participant->share_token ? route('trips.participant', $participant->share_token) : null]);
        $summary = GroupTrip::summary($trip, $folderLink);

        return [
            'trip' => $trip,
            'folderLink' => $folderLink,
            'links' => $links,
            'summary' => $summary,
            'messages' => $trip->participants->mapWithKeys(fn (TripParticipant $participant) => [$participant->id => $links[$participant->id] ? GroupTrip::personalMessage($trip, $participant, $links[$participant->id]) : null]),
            'counts' => $trip->participants->countBy('status'),
            'costs' => GroupTrip::costs($trip),
            'files' => GroupTrip::files($trip),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $statuses = \App\Models\TripParticipant::statuses();
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('trips.show', $trip) }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ $trip->title }}</a>
            <h2 class="w-full font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">👥 {{ __('Group & trip folder') }}</h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            {{-- Participants --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="participants">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 id="participants" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Participants') }}</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        @foreach ($statuses as $key => $status)
                            @if ($counts[$key] ?? 0)
                                <span class="me-2">{{ $status['icon'] }} {{ $counts[$key] }}</span>
                            @endif
                        @endforeach
                    </p>
                </div>

                <form wire:submit="addParticipants" class="mt-3 flex flex-wrap gap-2">
                    <input type="text" wire:model="names" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" placeholder="{{ __('Names separated by commas, e.g. Anna, Ben, Carla') }}" aria-label="{{ __('Add participants') }}">
                    <x-primary-button>+ {{ __('Add') }}</x-primary-button>
                    @if ($trip->travelers && $trip->participants->isEmpty())
                        <x-secondary-button type="button" wire:click="importTravelers">{{ __('Take over travellers') }}</x-secondary-button>
                    @endif
                </form>
                <x-input-error :messages="$errors->get('names')" class="mt-1" />

                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($trip->participants as $member)
                        @php($memberCosts = $costs[mb_strtolower(trim($member->name))] ?? null)
                        <li wire:key="participant-{{ $member->id }}" class="py-3">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $member->name }}</span>
                                    <span class="ms-2 text-xs text-gray-500 dark:text-gray-400">{{ $statuses[$member->status]['icon'] }} {{ $statuses[$member->status]['label'] }}@if ($member->replied_at) · {{ $member->replied_at->diffForHumans() }}@endif</span>
                                    @if ($memberCosts && abs($memberCosts['balance']) >= 0.01)
                                        <span @class(['ms-2 text-xs', 'text-emerald-700 dark:text-emerald-300' => $memberCosts['balance'] > 0, 'text-red-700 dark:text-red-300' => $memberCosts['balance'] < 0])>{{ $memberCosts['balance'] > 0 ? '+' : '−' }}{{ $euro(abs($memberCosts['balance'])) }}</span>
                                    @endif
                                    @if ($member->reply)
                                        <span class="mt-0.5 block text-sm text-gray-600 dark:text-gray-400">💬 {{ $member->reply }}</span>
                                    @endif
                                </span>
                                @if ($links[$member->id])
                                    <button type="button" x-data x-on:click="navigator.clipboard.writeText(@js($links[$member->id])); $dispatch('toast', { message: @js(__('Link copied.')) })" class="text-sm text-indigo-600 underline dark:text-indigo-400">🔗 {{ __('Copy link') }}</button>
                                    <a href="{{ \App\Support\Life\GroupTrip::whatsappUrl($messages[$member->id], $member->whatsappNumber()) }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-emerald-600 px-2.5 py-1 text-sm text-white hover:bg-emerald-700" title="{{ $member->whatsappNumber() ? __('Send via WhatsApp') : __('WhatsApp (choose contact)') }}">WhatsApp</a>
                                @endif
                                <button type="button" wire:click="edit({{ $member->id }})" class="text-sm text-gray-500 underline dark:text-gray-400">{{ __('Edit') }}</button>
                            </div>

                            @if ($editingId === $member->id)
                                <form wire:submit="saveParticipant" class="mt-2 grid gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-6 dark:bg-gray-900/40">
                                    <label class="text-xs text-gray-600 sm:col-span-2 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="participant.name" class="{{ $input }}"><x-input-error :messages="$errors->get('participant.name')" /></label>
                                    <label class="text-xs text-gray-600 sm:col-span-2 dark:text-gray-300">{{ __('Mobile number (for WhatsApp)') }}<input type="tel" wire:model="participant.phone" class="{{ $input }}"></label>
                                    <label class="text-xs text-gray-600 sm:col-span-2 dark:text-gray-300">{{ __('E-mail') }}<input type="email" wire:model="participant.email" class="{{ $input }}"><x-input-error :messages="$errors->get('participant.email')" /></label>
                                    <label class="text-xs text-gray-600 sm:col-span-4 dark:text-gray-300">{{ __('Personal information (only on their page)') }}<textarea wire:model="participant.personal_info" rows="2" class="{{ $input }}" placeholder="{{ __('e.g. room 12 with Ben, seat 14A, please bring the first-aid kit') }}"></textarea></label>
                                    <label class="text-xs text-gray-600 sm:col-span-2 dark:text-gray-300">{{ __('Status') }}
                                        <select wire:model="participant.status" class="{{ $input }}">
                                            @foreach ($statuses as $key => $status)
                                                <option value="{{ $key }}">{{ $status['icon'] }} {{ $status['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <div class="flex flex-wrap gap-2 sm:col-span-6">
                                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                                        <x-secondary-button type="button" wire:click="$set('editingId', null)">{{ __('Cancel') }}</x-secondary-button>
                                        <button type="button" wire:click="renewLink({{ $member->id }})" wire:confirm="{{ __('Create a new personal link? The old one stops working.') }}" class="text-sm text-gray-600 underline dark:text-gray-300">{{ __('New link') }}</button>
                                        <button type="button" wire:click="removeParticipant({{ $member->id }})" wire:confirm="{{ __('Remove this participant?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Remove') }}</button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @empty
                        <li class="py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No participants yet. Everybody gets a personal link to reply and to see their own details and costs.') }}</li>
                    @endforelse
                </ul>
            </section>

            {{-- Group message --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="summary">
                <h3 id="summary" class="font-semibold text-gray-900 dark:text-gray-100">📣 {{ __('Summary for everybody') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Built from the trip, the bookings with a time (programme) and the information below.') }}</p>
                <textarea readonly rows="8" class="{{ $input }} font-mono text-xs" aria-label="{{ __('Summary for everybody') }}">{{ $summary }}</textarea>
                <div class="mt-2 flex flex-wrap gap-2">
                    <a href="{{ \App\Support\Life\GroupTrip::whatsappUrl($summary) }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm text-white hover:bg-emerald-700">{{ __('Send to WhatsApp group') }}</a>
                    <button type="button" x-data x-on:click="navigator.clipboard.writeText(@js($summary)); $dispatch('toast', { message: @js(__('Copied.')) })" class="rounded-lg px-3 py-1.5 text-sm ring-1 ring-gray-300 dark:ring-gray-600">📋 {{ __('Copy') }}</button>
                </div>
            </section>

            {{-- Trip folder --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="folder">
                <h3 id="folder" class="font-semibold text-gray-900 dark:text-gray-100">🔒 {{ __('Trip folder for external people') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Shows information, programme, documents (:count files) and the participant list – protected by a link and a password.', ['count' => $files->count()]) }}</p>

                <form wire:submit="saveFolder" class="mt-3 grid gap-3 sm:grid-cols-6">
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Meeting point') }}<input type="text" wire:model="folder.meeting_point" class="{{ $input }}" placeholder="{{ __('e.g. Saturday 7:30 at the club house') }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Contact for questions') }}<input type="text" wire:model="folder.organizer_contact" class="{{ $input }}" placeholder="{{ __('e.g. Tobi, 0171 1234567') }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Information for participants') }}<textarea wire:model="folder.group_info" rows="4" class="{{ $input }}" placeholder="{{ __('e.g. what to bring, costs per person, bank details for the deposit') }}"></textarea></label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-6 dark:text-gray-300"><input type="checkbox" wire:model="folder.share_references" class="rounded border-gray-300 text-indigo-600"> {{ __('Show booking codes in the folder') }}</label>
                    <div class="sm:col-span-6"><x-primary-button>{{ __('Save') }}</x-primary-button></div>
                </form>

                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                    @if ($folderLink)
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="text" readonly value="{{ $folderLink }}" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" aria-label="{{ __('Link to the trip folder') }}">
                            <button type="button" x-data x-on:click="navigator.clipboard.writeText(@js($folderLink)); $dispatch('toast', { message: @js(__('Link copied.')) })" class="rounded-lg px-3 py-1.5 text-sm ring-1 ring-gray-300 dark:ring-gray-600">📋 {{ __('Copy') }}</button>
                            <a href="{{ $folderLink }}" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-600 underline dark:text-indigo-400">{{ __('Open folder') }} ↗</a>
                        </div>
                        <x-share-expiry class="mt-2" :expires="$trip->share_expires_at" action="setFolderExpiry" />
                        <form wire:submit="changePassword" class="mt-3 flex flex-wrap items-start gap-2">
                            <input type="password" wire:model="password" autocomplete="new-password" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="{{ __('New password') }}" aria-label="{{ __('New password') }}">
                            <x-secondary-button type="submit">{{ __('Change password') }}</x-secondary-button>
                            <button type="button" wire:click="revokeFolderLink" wire:confirm="{{ __('Turn the trip folder off? The link stops working.') }}" class="self-center text-sm text-red-600 underline dark:text-red-400">{{ __('Turn link off') }}</button>
                        </form>
                    @else
                        <form wire:submit="createFolderLink" class="flex flex-wrap items-start gap-2">
                            <input type="password" wire:model="password" autocomplete="new-password" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="{{ __('Password for the folder (min. 6 characters)') }}" aria-label="{{ __('Password') }}">
                            <x-primary-button>{{ __('Create trip folder') }}</x-primary-button>
                        </form>
                    @endif
                    <x-input-error :messages="$errors->get('password')" class="mt-1" />
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Tip: send the password in a separate message. Participants do not need it – their personal link is enough.') }}</p>
                </div>
            </section>
        </div>
    </div>
</div>
