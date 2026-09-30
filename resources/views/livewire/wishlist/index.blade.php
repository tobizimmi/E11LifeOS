<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\SavingsGoal;
use App\Models\WishlistItem;
use App\Support\Life\Affordability;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Wishes with reason, priority and price, checked against the monthly surplus and the reserves.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array{title: string, price: string, priority: string, category: string, reason: string, url: string, wanted_by: string, private: bool}
     */
    public array $wish = ['title' => '', 'price' => '', 'priority' => 'want', 'category' => 'other', 'reason' => '', 'url' => '', 'wanted_by' => '', 'private' => false];

    public function mount(): void
    {
        $this->resolveHousehold();
    }

    private function findWish(int $id): WishlistItem
    {
        return WishlistItem::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($id) ?? abort(404);
    }

    public function create(): void
    {
        $this->reset(['editingId', 'wish']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $wish = $this->findWish($id);
        $this->editingId = $wish->id;
        $this->wish = [
            'title' => $wish->title,
            'price' => $wish->price !== null ? number_format($wish->price, 2, ',', '') : '',
            'priority' => $wish->priority,
            'category' => $wish->category,
            'reason' => (string) $wish->reason,
            'url' => (string) $wish->url,
            'wanted_by' => (string) $wish->wanted_by?->toDateString(),
            'private' => $wish->isPrivate(),
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->wish['price'] = str_replace(['.', ','], ['', '.'], trim($this->wish['price']));
        $data = $this->validate([
            'wish.title' => ['required', 'string', 'max:150'],
            'wish.price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'wish.priority' => ['required', Rule::in(WishlistItem::PRIORITIES)],
            'wish.category' => ['required', Rule::in(array_keys(WishlistItem::categories()))],
            'wish.reason' => ['nullable', 'string', 'max:2000'],
            'wish.url' => ['nullable', 'url:http,https', 'max:500'],
            'wish.wanted_by' => ['nullable', 'date'],
        ], attributes: ['wish.title' => __('What do you want?'), 'wish.price' => __('What does it cost?'), 'wish.url' => __('Link')])['wish'];

        $attributes = [
            'title' => $data['title'],
            'price' => $data['price'] !== '' && $data['price'] !== null ? round((float) $data['price'], 2) : null,
            'priority' => $data['priority'],
            'category' => $data['category'],
            'reason' => trim((string) $data['reason']) ?: null,
            'url' => trim((string) $data['url']) ?: null,
            'wanted_by' => $data['wanted_by'] ?: null,
            'visibility' => $this->wish['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
        ];

        if ($this->editingId) {
            $wish = $this->findWish($this->editingId);
            $wish->update($wish->created_by === Auth::id() ? $attributes : array_diff_key($attributes, ['visibility' => true]));
        } else {
            WishlistItem::create($attributes + [
                'household_id' => $this->householdId,
                'created_by' => Auth::id(),
                'position' => (int) WishlistItem::where('household_id', $this->householdId)->max('position') + 1,
            ]);
        }

        $this->reset(['editingId', 'wish', 'showForm']);
    }

    public function decide(int $id, string $status): void
    {
        abort_unless(in_array($status, WishlistItem::STATUSES, true), 422);
        $this->findWish($id)->update(['status' => $status, 'decided_at' => $status === 'open' ? null : now()]);
    }

    public function delete(int $id): void
    {
        $this->findWish($id)->delete();
        $this->reset(['editingId', 'wish', 'showForm']);
    }

    /**
     * Turns the wish into a savings goal (net worth page) that tracks the progress.
     */
    public function createSavingsGoal(int $id): void
    {
        $wish = $this->findWish($id);
        abort_if(! $wish->price, 422);

        $goal = $wish->savingsGoal ?? SavingsGoal::create([
            'user_id' => Auth::id(),
            'name' => mb_substr($wish->title, 0, 100),
            'icon' => WishlistItem::categories()[$wish->category]['icon'] ?? '🎯',
            'target_amount' => $wish->price,
            'saved_amount' => 0,
            'target_date' => $wish->wanted_by,
            'position' => (int) SavingsGoal::where('user_id', Auth::id())->max('position') + 1,
        ]);
        $wish->update(['savings_goal_id' => $goal->id]);

        $this->dispatch('toast', message: __('Savings goal “:name” created.', ['name' => $goal->name]));
    }

    public function with(): array
    {
        $wishes = WishlistItem::query()->visibleTo(Auth::user(), (int) $this->householdId)->with('savingsGoal.bankAccount')->orderBy('position')->get();
        $open = $wishes->where('status', 'open')->values();
        $budget = Affordability::budget(Auth::user());

        return [
            'budget' => $budget,
            'checks' => Affordability::checkAll($open, $budget),
            'openByPriority' => collect(WishlistItem::PRIORITIES)->mapWithKeys(fn (string $priority) => [$priority => $open->where('priority', $priority)->values()])->filter->isNotEmpty(),
            'openTotal' => round((float) $open->sum('price'), 2),
            'decided' => $wishes->where('status', '!=', 'open')->sortByDesc('decided_at')->values(),
            'droppedTotal' => round((float) $wishes->where('status', 'dropped')->sum('price'), 2),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (?float $value) => $value === null ? '–' : number_format($value, 2, ',', '.').' €';
    $levels = [
        'green' => ['label' => __('Affordable'), 'class' => 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-900/40 dark:text-emerald-200 dark:ring-emerald-800', 'dot' => '🟢'],
        'yellow' => ['label' => __('Save up first'), 'class' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-900/40 dark:text-amber-200 dark:ring-amber-800', 'dot' => '🟡'],
        'red' => ['label' => __('Not affordable yet'), 'class' => 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-900/40 dark:text-red-200 dark:ring-red-800', 'dot' => '🔴'],
        'unknown' => ['label' => __('Not checked'), 'class' => 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700', 'dot' => '⚪'],
    ];
    $priorities = \App\Models\WishlistItem::priorities();
    $categories = \App\Models\WishlistItem::categories();
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🎁 {{ __('Wishlist') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <section class="grid gap-3 sm:grid-cols-3" aria-label="{{ __('Your budget') }}">
                <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Monthly surplus') }}</p>
                    <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-300' => $budget['surplus'] > 0, 'text-red-700 dark:text-red-300' => $budget['surplus'] <= 0])>{{ $euro($budget['surplus']) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Free reserves') }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($budget['available']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':liquid available minus emergency fund of :reserve', ['liquid' => $euro($budget['liquid']), 'reserve' => $euro($budget['reserve'])]) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Open wishes') }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($openTotal) }}</p>
                    <a href="{{ route('cashflow.index') }}" wire:navigate class="text-xs text-indigo-600 underline dark:text-indigo-400">{{ __('Figures from the cashflow analysis') }}</a>
                </div>
            </section>

            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="create">+ {{ __('New wish') }}</x-primary-button>
            </div>

            @if ($showForm)
                <form wire:submit="save" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('What do you want?') }}<input type="text" wire:model="wish.title" class="{{ $input }}" placeholder="{{ __('e.g. road bike, new sofa, weekend in Vienna') }}" autofocus><x-input-error :messages="$errors->get('wish.title')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('What does it cost?') }}<input type="text" inputmode="decimal" wire:model="wish.price" class="{{ $input }}" placeholder="0,00"><x-input-error :messages="$errors->get('wish.price')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Why do you want it?') }}<textarea wire:model="wish.reason" rows="2" class="{{ $input }}" placeholder="{{ __('e.g. old one is broken, want to ride to work') }}"></textarea></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Priority') }}
                        <select wire:model="wish.priority" class="{{ $input }}">
                            @foreach ($priorities as $key => $priority)
                                <option value="{{ $key }}">{{ $priority['icon'] }} {{ $priority['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Category') }}
                        <select wire:model="wish.category" class="{{ $input }}">
                            @foreach ($categories as $key => $category)
                                <option value="{{ $key }}">{{ $category['icon'] }} {{ $category['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Wanted by') }}<input type="date" wire:model="wish.wanted_by" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('Link') }}<input type="url" wire:model="wish.url" class="{{ $input }}" placeholder="https://"><x-input-error :messages="$errors->get('wish.url')" /></label>
                    <label class="flex items-center gap-2 self-end text-sm text-gray-700 sm:col-span-2 dark:text-gray-300"><input type="checkbox" wire:model="wish.private" class="rounded border-gray-300 text-indigo-600"> {{ __('Only visible to me') }}</label>
                    <div class="flex flex-wrap gap-2 sm:col-span-6">
                        <x-primary-button>{{ $editingId ? __('Save changes') : __('Add') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                        @if ($editingId)
                            <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this wish?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                        @endif
                    </div>
                </form>
            @endif

            @forelse ($openByPriority as $priorityKey => $wishes)
                <section class="space-y-3" aria-labelledby="priority-{{ $priorityKey }}">
                    <h3 id="priority-{{ $priorityKey }}" class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $priorities[$priorityKey]['icon'] }} {{ $priorities[$priorityKey]['label'] }}</h3>
                    @foreach ($wishes as $item)
                        @php($check = $checks[$item->id])
                        <article wire:key="wish-{{ $item->id }}" class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $categories[$item->category]['icon'] ?? '🛍️' }} {{ $item->title }}
                                        @if ($item->isPrivate())
                                            <x-private-badge />
                                        @endif
                                    </p>
                                    @if ($item->reason)
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $item->reason }}</p>
                                    @endif
                                    <p class="mt-1 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                                        @if ($item->wanted_by)
                                            <span>📅 {{ __('by :date', ['date' => $item->wanted_by->format('d.m.Y')]) }}</span>
                                        @endif
                                        @if ($item->url)
                                            <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Open link') }} ↗</a>
                                        @endif
                                    </p>
                                </div>
                                <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($item->price) }}</p>
                            </div>

                            <div class="mt-3 rounded-lg px-3 py-2 text-sm ring-1 {{ $levels[$check['level']]['class'] }}" role="status">
                                <span class="font-medium"><span aria-hidden="true">{{ $levels[$check['level']]['dot'] }}</span> {{ $levels[$check['level']]['label'] }}:</span> {{ $check['text'] }}
                            </div>
                            @foreach ($check['hints'] as $hint)
                                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">💡 {{ $hint }}</p>
                            @endforeach

                            @if ($item->savingsGoal)
                                @php($saved = $item->savingsGoal->savedSoFar())
                                <div class="mt-3">
                                    <div class="flex justify-between text-xs text-gray-600 dark:text-gray-400"><span>🎯 {{ __('Savings goal') }}</span><span class="tabular-nums">{{ $euro($saved) }} / {{ $euro($item->savingsGoal->target_amount) }}</span></div>
                                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $item->savingsGoal->target_amount > 0 ? (int) min(100, $saved / $item->savingsGoal->target_amount * 100) : 0 }}">
                                        <div class="h-full rounded-full bg-indigo-500" style="width: {{ $item->savingsGoal->target_amount > 0 ? min(100, round($saved / $item->savingsGoal->target_amount * 100)) : 0 }}%"></div>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-3 flex flex-wrap gap-2 text-sm">
                                <button type="button" wire:click="decide({{ $item->id }}, 'bought')" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-white hover:bg-emerald-700">✓ {{ __('Bought') }}</button>
                                <button type="button" wire:click="decide({{ $item->id }}, 'dropped')" class="rounded-lg px-3 py-1.5 text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700">{{ __('Don\'t need it') }}</button>
                                @if ($item->price && ! $item->savingsGoal && $check['level'] !== 'green')
                                    <button type="button" wire:click="createSavingsGoal({{ $item->id }})" class="rounded-lg px-3 py-1.5 text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-indigo-900/30">🎯 {{ __('Create savings goal') }}</button>
                                @endif
                                <button type="button" wire:click="edit({{ $item->id }})" class="ms-auto text-gray-500 underline dark:text-gray-400">{{ __('Edit') }}</button>
                            </div>
                        </article>
                    @endforeach
                </section>
            @empty
                <section class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🎁</p>
                    <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No wishes yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Note what you would like to buy, why and how important it is – the app checks against your cashflow whether it fits now or when it will.') }}</p>
                </section>
            @endforelse

            @if ($decided->isNotEmpty())
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Decided') }}</h3>
                        @if ($droppedTotal > 0)
                            <p class="text-sm text-emerald-700 dark:text-emerald-300">{{ __('Not bought: :amount saved', ['amount' => $euro($droppedTotal)]) }}</p>
                        @endif
                    </div>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($decided as $item)
                            <li wire:key="decided-{{ $item->id }}" class="flex items-center gap-3 py-2 text-sm">
                                <span aria-hidden="true">{{ $item->status === 'bought' ? '✅' : '🚫' }}</span>
                                <span @class(['min-w-0 flex-1 truncate', 'text-gray-900 dark:text-gray-100' => $item->status === 'bought', 'text-gray-500 line-through dark:text-gray-400' => $item->status === 'dropped'])>{{ $item->title }}</span>
                                <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $euro($item->price) }}</span>
                                <button type="button" wire:click="decide({{ $item->id }}, 'open')" class="text-xs text-gray-500 underline dark:text-gray-400">{{ __('Back to list') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
