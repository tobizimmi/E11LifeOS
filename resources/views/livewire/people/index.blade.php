<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Contact;
use App\Models\GiftIdea;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Support\ShoppingLists;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * People and occasions: birthdays and anniversaries with reminders, contact details, notes and
 * gift ideas that go on the shopping list with one click.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(as: 'person')]
    public ?int $personId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array<string, mixed>
     */
    public array $contact = [];

    /**
     * @var array{title: string, url: string, price: string, occasion: string}
     */
    public array $gift = ['title' => '', 'url' => '', 'price' => '', 'occasion' => ''];

    public function mount(): void
    {
        $this->resolveHousehold();

        if ($this->personId !== null && ! $this->query()->whereKey($this->personId)->exists()) {
            $this->personId = null;
        }

        $this->resetContact();
    }

    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Contact::query()->visibleTo($this->currentUser(), (int) $this->householdId);
    }

    private function findContact(int $id): Contact
    {
        return $this->query()->find($id) ?? abort(404);
    }

    private function resetContact(): void
    {
        $this->contact = ['name' => '', 'relation' => '', 'birthday' => '', 'birth_year_known' => true, 'anniversary' => '', 'anniversary_label' => '', 'phone' => '', 'email' => '', 'address' => '', 'notes' => '', 'remind_days' => '7', 'private' => false];
        $this->resetValidation();
    }

    public function add(): void
    {
        $this->resetContact();
        $this->editingId = null;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $person = $this->findContact($id);
        $this->resetContact();
        $this->editingId = $person->id;
        $this->contact = [
            'name' => $person->name,
            'relation' => (string) $person->relation,
            'birthday' => (string) $person->birthday?->toDateString(),
            'birth_year_known' => $person->birth_year_known,
            'anniversary' => (string) $person->anniversary?->toDateString(),
            'anniversary_label' => (string) $person->anniversary_label,
            'phone' => (string) $person->phone,
            'email' => (string) $person->email,
            'address' => (string) $person->address,
            'notes' => (string) $person->notes,
            'remind_days' => (string) $person->remind_days,
            'private' => $person->visibility === Item::VISIBILITY_PRIVATE,
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'contact.name' => ['required', 'string', 'max:120'],
            'contact.relation' => ['nullable', 'string', 'max:64'],
            'contact.birthday' => ['nullable', 'date', 'before_or_equal:today'],
            'contact.birth_year_known' => ['boolean'],
            'contact.anniversary' => ['nullable', 'date'],
            'contact.anniversary_label' => ['nullable', 'string', 'max:64'],
            'contact.phone' => ['nullable', 'string', 'max:64'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.address' => ['nullable', 'string', 'max:500'],
            'contact.notes' => ['nullable', 'string', 'max:5000'],
            'contact.remind_days' => ['required', 'integer', 'between:0,60'],
        ], attributes: ['contact.name' => __('Name')])['contact'];

        $attributes = collect($data)->map(fn ($value) => $value === '' ? null : $value)->all();
        $attributes['remind_days'] = (int) $data['remind_days'];

        if ($this->editingId) {
            $person = $this->findContact($this->editingId);

            if ($person->created_by === Auth::id()) {
                $attributes['visibility'] = $this->contact['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
            }

            $person->update($attributes);
        } else {
            $person = Contact::create($attributes + ['household_id' => $this->householdId, 'created_by' => Auth::id(), 'visibility' => $this->contact['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED]);
        }

        $this->personId = $person->id;
        $this->showForm = false;
        $this->editingId = null;
    }

    public function delete(int $id): void
    {
        $this->findContact($id)->delete();
        $this->personId = null;
        $this->showForm = false;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
    }

    public function select(int $id): void
    {
        $this->personId = $this->findContact($id)->id;
    }

    public function contacted(int $id): void
    {
        $this->findContact($id)->update(['last_contact_on' => today()]);
    }

    public function addGift(): void
    {
        $data = $this->validate([
            'gift.title' => ['required', 'string', 'max:255'],
            'gift.url' => ['nullable', 'url:http,https', 'max:2000'],
            'gift.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'gift.occasion' => ['nullable', 'string', 'max:64'],
        ], attributes: ['gift.title' => __('Gift idea')])['gift'];

        $this->findContact((int) $this->personId)->giftIdeas()->create(collect($data)->map(fn ($value) => $value === '' ? null : $value)->all() + ['created_by' => Auth::id()]);
        $this->gift = ['title' => '', 'url' => '', 'price' => '', 'occasion' => ''];
    }

    public function setGiftStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, GiftIdea::STATUSES, true), 404);

        $this->findGift($id)->update(['status' => $status, 'given_on' => $status === 'given' ? today() : null]);
    }

    public function giftToShopping(int $id): void
    {
        $gift = $this->findGift($id);
        ShoppingLists::add(ShoppingList::defaultFor((int) $this->householdId), $gift->title, null, Auth::id(), ['note' => __('Gift for :name', ['name' => $gift->contact->name]), 'price' => $gift->price]);
        $this->dispatch('toast', message: __(':item is on the shopping list.', ['item' => $gift->title]));
    }

    public function deleteGift(int $id): void
    {
        $this->findGift($id)->delete();
    }

    private function findGift(int $id): GiftIdea
    {
        return GiftIdea::whereKey($id)->whereIn('contact_id', $this->query()->select('id'))->first() ?? abort(404);
    }

    public function with(): array
    {
        $people = $this->query()
            ->when(trim($this->search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('name')->get();

        $upcoming = $people->filter(fn (Contact $person) => $person->birthday)->map(fn (Contact $person) => ['person' => $person, 'date' => $person->nextBirthday()])
            ->filter(fn (array $row) => $row['date']->lte(today()->addDays(60)))->sortBy('date')->values();

        return [
            'people' => $people,
            'upcoming' => $upcoming,
            'selected' => $this->personId ? $this->query()->with('giftIdeas')->find($this->personId) : null,
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $days = fn (\Illuminate\Support\Carbon $date) => (int) today()->diffInDays($date);
    $statusLabels = ['idea' => __('Idea'), 'bought' => __('Bought'), 'given' => __('Given')];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('People & occasions') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            @if ($upcoming->isNotEmpty())
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🎉 {{ __('Next birthdays') }}</h3>
                    <ul class="mt-3 flex gap-3 overflow-x-auto pb-1">
                        @foreach ($upcoming as $row)
                            <li wire:key="upcoming-{{ $row['person']->id }}" class="shrink-0">
                                <button type="button" wire:click="select({{ $row['person']->id }})" class="w-40 rounded-xl p-3 text-left ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-gray-700/40">
                                    <p class="truncate font-medium text-gray-900 dark:text-gray-100">{{ $row['person']->name }}</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $row['date']->translatedFormat('j. M') }}@if ($row['person']->turns()) · {{ __('turns :age', ['age' => $row['person']->turns()]) }}@endif</p>
                                    <p @class(['text-xs', 'font-semibold text-rose-700 dark:text-rose-300' => $days($row['date']) <= 7, 'text-gray-500 dark:text-gray-400' => $days($row['date']) > 7])>{{ $days($row['date']) === 0 ? __('today! 🎉') : trans_choice('in :count day|in :count days', $days($row['date'])) }}</p>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <div class="grid gap-5 md:grid-cols-5">
                <section class="space-y-3 md:col-span-2">
                    <div class="flex gap-2">
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search people…') }}" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <x-primary-button type="button" wire:click="add">+</x-primary-button>
                    </div>
                    <ul class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                        @forelse ($people as $person)
                            <li wire:key="person-{{ $person->id }}" class="border-b border-gray-100 last:border-0 dark:border-gray-700">
                                <button type="button" wire:click="select({{ $person->id }})" @class(['flex w-full items-center justify-between gap-2 px-4 py-3 text-left', 'bg-indigo-50 dark:bg-indigo-900/30' => $selected?->id === $person->id, 'hover:bg-gray-50 dark:hover:bg-gray-700/40' => $selected?->id !== $person->id])>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $person->name }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ collect([$person->relation, $person->birthday ? '🎂 '.$person->birthday->format($person->birth_year_known ? 'd.m.Y' : 'd.m.') : null])->filter()->implode(' · ') }}</span>
                                    </span>
                                    @if ($person->visibility === 'private')
                                        <x-private-badge />
                                    @endif
                                </button>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No people yet – add family and friends with their birthdays.') }}</li>
                        @endforelse
                    </ul>
                </section>

                <section class="md:col-span-3">
                    @if ($selected)
                        <div class="space-y-4 rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $selected->name }}</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $selected->relation }}</p>
                                </div>
                                <button type="button" wire:click="edit({{ $selected->id }})" class="text-gray-400 hover:text-indigo-600" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                            </div>
                            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                                @if ($selected->birthday)
                                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Birthday') }}</dt><dd class="text-gray-900 dark:text-gray-100">{{ $selected->birthday->format($selected->birth_year_known ? 'd.m.Y' : 'd.m.') }} · {{ trans_choice('in :count day|in :count days', $days($selected->nextBirthday())) }}@if ($selected->turns()) ({{ __('turns :age', ['age' => $selected->turns()]) }})@endif</dd></div>
                                @endif
                                @if ($selected->anniversary)
                                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">{{ $selected->anniversary_label ?: __('Anniversary') }}</dt><dd class="text-gray-900 dark:text-gray-100">{{ $selected->anniversary->format('d.m.Y') }}</dd></div>
                                @endif
                                @if ($selected->phone)
                                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Phone') }}</dt><dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $selected->phone) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $selected->phone }}</a></dd></div>
                                @endif
                                @if ($selected->email)
                                    <div><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Email') }}</dt><dd><a href="mailto:{{ $selected->email }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $selected->email }}</a></dd></div>
                                @endif
                                @if ($selected->address)
                                    <div class="sm:col-span-2"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Address') }}</dt><dd class="whitespace-pre-line text-gray-900 dark:text-gray-100">{{ $selected->address }}</dd></div>
                                @endif
                                @if ($selected->notes)
                                    <div class="sm:col-span-2"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Notes') }}</dt><dd class="whitespace-pre-line text-gray-900 dark:text-gray-100">{{ $selected->notes }}</dd></div>
                                @endif
                            </dl>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <span class="text-gray-500 dark:text-gray-400">{{ $selected->last_contact_on ? __('Last contact :date', ['date' => $selected->last_contact_on->format('d.m.Y')]) : __('No contact noted yet') }}</span>
                                <button type="button" wire:click="contacted({{ $selected->id }})" class="rounded-full px-3 py-1 text-xs ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-600 dark:hover:bg-gray-700">👋 {{ __('Had contact today') }}</button>
                            </div>

                            <div class="border-t border-gray-100 pt-4 dark:border-gray-700">
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100">🎁 {{ __('Gift ideas') }}</h4>
                                <ul class="mt-2 space-y-2 text-sm">
                                    @forelse ($selected->giftIdeas as $idea)
                                        <li wire:key="gift-{{ $idea->id }}" @class(['flex flex-wrap items-center gap-2', 'opacity-60' => $idea->status === 'given'])>
                                            <span class="min-w-0 flex-1 text-gray-900 dark:text-gray-100">
                                                @if ($idea->url)
                                                    <a href="{{ $idea->url }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $idea->title }}</a>
                                                @else
                                                    {{ $idea->title }}
                                                @endif
                                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ collect([$idea->occasion, $idea->price !== null ? number_format($idea->price, 2, ',', '.').' €' : null, $idea->given_on ? __('given on :date', ['date' => $idea->given_on->format('d.m.Y')]) : null])->filter()->implode(' · ') }}</span>
                                            </span>
                                            <select wire:change="setGiftStatus({{ $idea->id }}, $event.target.value)" class="rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" aria-label="{{ __('Status') }}">
                                                @foreach ($statusLabels as $key => $label)
                                                    <option value="{{ $key }}" @selected($idea->status === $key)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @if ($idea->status === 'idea')
                                                <button type="button" wire:click="giftToShopping({{ $idea->id }})" class="text-gray-400 hover:text-indigo-600" title="{{ __('Put on the shopping list') }}" aria-label="{{ __('Put on the shopping list') }}"><x-icon name="shopping-cart" class="h-5 w-5" /></button>
                                            @endif
                                            <button type="button" wire:click="deleteGift({{ $idea->id }})" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                                        </li>
                                    @empty
                                        <li class="text-gray-500 dark:text-gray-400">{{ __('No gift ideas yet – note them down whenever something comes to mind.') }}</li>
                                    @endforelse
                                </ul>
                                <form wire:submit="addGift" class="mt-3 grid gap-2 sm:grid-cols-6">
                                    <input type="text" wire:model="gift.title" placeholder="{{ __('Gift idea') }}" class="rounded-md border-gray-300 text-sm shadow-sm sm:col-span-3 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <input type="text" wire:model="gift.occasion" placeholder="{{ __('Occasion') }}" class="rounded-md border-gray-300 text-sm shadow-sm sm:col-span-2 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <input type="text" inputmode="decimal" wire:model="gift.price" placeholder="€" class="rounded-md border-gray-300 text-sm shadow-sm sm:col-span-1 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <input type="url" wire:model="gift.url" placeholder="https://" class="rounded-md border-gray-300 text-sm shadow-sm sm:col-span-5 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    <x-secondary-button type="submit" class="sm:col-span-1">+</x-secondary-button>
                                    <x-input-error class="sm:col-span-6" :messages="array_merge($errors->get('gift.title'), $errors->get('gift.url'), $errors->get('gift.price'))" />
                                </form>
                            </div>
                        </div>
                    @else
                        <div class="rounded-2xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm dark:bg-gray-800 dark:text-gray-400">{{ __('Pick a person or add a new one.') }}</div>
                    @endif
                </section>
            </div>
        </div>
    </div>

    @if ($showForm)
        <x-sheet close="closeForm" :label="__('Person')" size="md">
            <x-slot name="header"><h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $editingId ? __('Edit person') : __('New person') }}</h3></x-slot>
            <form wire:submit="save" id="person-form" @if (! $editingId) data-draft="person" data-draft-restored="{{ __('Draft restored') }}" @endif class="grid gap-3 p-5 sm:grid-cols-2">
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="contact.name" class="{{ $input }}"><x-input-error :messages="$errors->get('contact.name')" /></label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Relationship') }}<input type="text" wire:model="contact.relation" class="{{ $input }}" placeholder="{{ __('e.g. Sister, friend') }}"></label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Birthday') }}<input type="date" wire:model="contact.birthday" class="{{ $input }}"><x-input-error :messages="$errors->get('contact.birthday')" /></label>
                <label class="flex items-end gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="contact.birth_year_known" class="rounded border-gray-300 text-indigo-600"> {{ __('Year of birth is correct') }}</label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Anniversary') }}<input type="date" wire:model="contact.anniversary" class="{{ $input }}"></label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Anniversary name') }}<input type="text" wire:model="contact.anniversary_label" class="{{ $input }}" placeholder="{{ __('e.g. Wedding day') }}"></label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Phone') }}<input type="tel" wire:model="contact.phone" class="{{ $input }}"></label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Email') }}<input type="email" wire:model="contact.email" class="{{ $input }}"><x-input-error :messages="$errors->get('contact.email')" /></label>
                <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Address') }}<textarea wire:model="contact.address" rows="2" class="{{ $input }}"></textarea></label>
                <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Notes') }}<textarea wire:model="contact.notes" rows="3" class="{{ $input }}" placeholder="{{ __('Interests, sizes, children’s names …') }}"></textarea></label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Remind days before') }}<input type="number" min="0" max="60" wire:model="contact.remind_days" class="{{ $input }}"></label>
                <label class="flex items-end gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="contact.private" class="rounded border-gray-300 text-indigo-600"> {{ __('Only visible to me') }}</label>
            </form>
            <x-slot name="footer">
                <div class="flex items-center gap-2">
                    <x-primary-button form="person-form">{{ __('Save') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeForm">{{ __('Cancel') }}</x-secondary-button>
                    @if ($editingId)
                        <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this person with all gift ideas?') }}" class="ms-auto text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="h-5 w-5" /></button>
                    @endif
                </div>
            </x-slot>
        </x-sheet>
    @endif
</div>
