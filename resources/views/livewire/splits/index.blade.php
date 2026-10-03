<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\Trip;
use App\Support\Life\ExpenseSplitter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Groups that share expenses (holiday with friends, shared flat, club trip) – like Splid or Tricount.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public bool $showForm = false;

    /** Opens the form prefilled for this trip (link from the trip page). */
    #[Url]
    public string $trip = '';

    /**
     * @var array{title: string, icon: string, people: string, trip_id: string, private: bool}
     */
    public array $group = ['title' => '', 'icon' => '🧾', 'people' => '', 'trip_id' => '', 'private' => false];

    public function mount(): void
    {
        $this->resolveHousehold();
        $tripId = (int) ($this->trip ?: request()->query('trip'));

        if ($tripId && ($trip = Trip::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($tripId))) {
            $this->group = ['title' => $trip->title, 'icon' => '✈️', 'people' => (string) $trip->travelers, 'trip_id' => (string) $trip->id, 'private' => false];
            $this->showForm = true;
        }
    }

    public function create(): void
    {
        $data = $this->validate([
            'group.title' => ['required', 'string', 'max:150'],
            'group.icon' => ['required', Rule::in(ExpenseGroup::ICONS)],
            'group.people' => ['nullable', 'string', 'max:1000'],
            'group.trip_id' => ['nullable', 'integer'],
        ], attributes: ['group.title' => __('Title')])['group'];

        $trip = $data['trip_id'] ? Trip::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($data['trip_id']) : null;
        $created = ExpenseGroup::create([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'visibility' => $this->group['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => $data['title'],
            'icon' => $data['icon'],
            'trip_id' => $trip?->id,
        ]);

        // The creator takes part; the other names come from the comma-separated list.
        $names = collect(preg_split('/[,;\n]+/', (string) $data['people']))->map(fn ($name) => trim($name))->filter()
            ->reject(fn ($name) => mb_strtolower($name) === mb_strtolower(Auth::user()->name))->unique()->take(50)->values();
        $created->members()->create(['user_id' => Auth::id(), 'name' => Auth::user()->name, 'position' => 0]);
        $names->each(fn ($name, $index) => $created->members()->create(['name' => mb_substr($name, 0, 80), 'position' => $index + 1]));

        $this->redirectRoute('splits.show', ['group' => $created->id], navigate: true);
    }

    public function with(): array
    {
        $groups = ExpenseGroup::query()->visibleTo(Auth::user(), (int) $this->householdId)->with(['members', 'expenses.shares', 'shareGroups'])->latest()->get();
        $me = fn (ExpenseGroup $group) => $group->members->firstWhere('user_id', Auth::id());

        return [
            'active' => $groups->whereNull('archived_at')->values(),
            'archived' => $groups->whereNotNull('archived_at')->values(),
            'myBalance' => $groups->mapWithKeys(fn (ExpenseGroup $group) => [$group->id => ($member = $me($group)) ? (ExpenseSplitter::balances($group)[$member->id]['balance'] ?? 0.0) : null])->all(),
            'totals' => $groups->mapWithKeys(fn (ExpenseGroup $group) => [$group->id => round((float) $group->expenses->reject->is_transfer->sum('amount'), 2)])->all(),
            'trips' => Trip::query()->visibleTo(Auth::user(), (int) $this->householdId)->orderByDesc('starts_on')->limit(30)->get(['id', 'title', 'starts_on']),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
@endphp

<div x-on:page-primary.window="$wire.set('showForm', true)">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Split costs') }}</h2>
            <x-primary-button type="button" x-on:click="$dispatch('page-primary')" class="shrink-0">+ {{ __('New group') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">

            @if ($showForm)
                <form wire:submit="create" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('Title') }}<input type="text" wire:model="group.title" class="{{ $input }}" placeholder="{{ __('e.g. Ski trip 2027, shared flat') }}" autofocus><x-input-error :messages="$errors->get('group.title')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Symbol') }}
                        <select wire:model="group.icon" class="{{ $input }}">
                            @foreach (\App\Models\ExpenseGroup::ICONS as $icon)
                                <option value="{{ $icon }}">{{ $icon }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Who takes part?') }}<input type="text" wire:model="group.people" class="{{ $input }}" placeholder="{{ __('Names separated by commas, e.g. Anna, Ben, Carla') }}"><span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('You are added automatically. People without an account only need a name.') }}</span></label>
                    @if ($trips->isNotEmpty())
                        <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Belongs to trip') }}
                            <select wire:model="group.trip_id" class="{{ $input }}">
                                <option value="">{{ __('none') }}</option>
                                @foreach ($trips as $trip)
                                    <option value="{{ $trip->id }}">{{ $trip->title }} ({{ $trip->starts_on->format('m/Y') }})</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label class="flex items-center gap-2 self-end text-sm text-gray-700 sm:col-span-3 dark:text-gray-300"><input type="checkbox" wire:model="group.private" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Only visible to me') }}</label>
                    <div class="flex gap-2 sm:col-span-6">
                        <x-primary-button>{{ __('Create') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                </form>
            @endif

            @forelse ($active as $expenseGroup)
                @php($balance = $myBalance[$expenseGroup->id])
                <a href="{{ route('splits.show', $expenseGroup) }}" wire:navigate wire:key="group-{{ $expenseGroup->id }}" class="block rounded-2xl bg-white p-5 shadow-xs transition hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $expenseGroup->icon }} {{ $expenseGroup->title }} <x-audience-badge :model="$expenseGroup" /></p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $expenseGroup->members->pluck('name')->implode(', ') }}</p>
                        </div>
                        @if ($balance !== null && abs($balance) >= 0.01)
                            <span @class(['rounded-full px-3 py-1 text-sm font-medium tabular-nums', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $balance > 0, 'bg-red-50 text-red-800 dark:bg-red-900/40 dark:text-red-200' => $balance < 0])>
                                {{ $balance > 0 ? __('You get :amount', ['amount' => $euro($balance)]) : __('You owe :amount', ['amount' => $euro(-$balance)]) }}
                            </span>
                        @elseif ($balance !== null)
                            <span class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-600 dark:bg-gray-700 dark:text-gray-300">✓ {{ __('Settled') }}</span>
                        @endif
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __(':count expenses · :total in total', ['count' => $expenseGroup->expenses->reject->is_transfer->count(), 'total' => $euro($totals[$expenseGroup->id])]) }}</p>
                </a>
            @empty
                <section class="rounded-2xl bg-white p-6 text-center shadow-xs dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🧾</p>
                    <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No group yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Note who paid what on a holiday with friends or in a shared flat – the app works out who owes whom and the fewest transfers to settle up.') }}</p>
                </section>
            @endforelse

            @if ($archived->isNotEmpty())
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Archived') }}</h3>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($archived as $expenseGroup)
                            <li wire:key="archived-{{ $expenseGroup->id }}"><a href="{{ route('splits.show', $expenseGroup) }}" wire:navigate class="flex items-center gap-3 py-2 text-sm hover:underline"><span class="min-w-0 flex-1 text-gray-900 dark:text-gray-100">{{ $expenseGroup->icon }} {{ $expenseGroup->title }}</span><span class="text-gray-500 dark:text-gray-400">{{ $euro($totals[$expenseGroup->id]) }}</span></a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
