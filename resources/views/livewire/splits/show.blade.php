<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Expense;
use App\Models\ExpenseGroup;
use App\Support\Life\ExpenseSplitter;
use App\Support\Life\TripPlanner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * One expense group: the expenses, who owes whom with the fewest transfers, the evaluation by
 * category, person and day, the participants, export and a read-only link for the others.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $groupId;

    /** expenses, balances, stats or people */
    #[Url(except: 'expenses')]
    public string $tab = 'expenses';

    public bool $showForm = false;

    public ?int $editingExpenseId = null;

    /**
     * @var array{title: string, amount: string, paid_by: string, spent_on: string, category: string, split_mode: string, notes: string}
     */
    public array $expense = [];

    /**
     * Who shares the expense: member id => true/false.
     *
     * @var array<int|string, bool>
     */
    public array $participants = [];

    /**
     * Shares or amounts per member for the modes "shares" and "amounts".
     *
     * @var array<int|string, string>
     */
    public array $values = [];

    public string $newMember = '';

    public function mount(ExpenseGroup $group): void
    {
        $this->resolveHousehold();
        $this->groupId = $this->findGroup($group->id)->id;
        $this->tab = in_array($this->tab, ['expenses', 'balances', 'stats', 'people'], true) ? $this->tab : 'expenses';
        $this->resetExpense();
    }

    private function findGroup(?int $id = null): ExpenseGroup
    {
        return ExpenseGroup::query()->visibleTo(Auth::user(), (int) $this->householdId)->with('members')->find($id ?? $this->groupId) ?? abort(404);
    }

    private function resetExpense(): void
    {
        $group = $this->findGroup();
        $me = $group->members->firstWhere('user_id', Auth::id()) ?? $group->members->first();
        $this->expense = ['title' => '', 'amount' => '', 'paid_by' => (string) $me?->id, 'spent_on' => today()->toDateString(), 'category' => 'other', 'split_mode' => 'equal', 'notes' => ''];
        $this->participants = $group->members->mapWithKeys(fn ($member) => [$member->id => true])->all();
        $this->values = $group->members->mapWithKeys(fn ($member) => [$member->id => $member->weight == 1 ? '1' : (string) $member->weight])->all();
        $this->editingExpenseId = null;
        $this->resetValidation();
    }

    public function newExpense(): void
    {
        $this->resetExpense();
        $this->showForm = true;
    }

    public function cancelExpense(): void
    {
        $this->resetExpense();
        $this->showForm = false;
    }

    public function editExpense(int $id): void
    {
        $expense = $this->findGroup()->expenses()->with('shares')->find($id) ?? abort(404);
        $this->expense = [
            'title' => $expense->title, 'amount' => number_format($expense->amount, 2, ',', ''), 'paid_by' => (string) $expense->paid_by,
            'spent_on' => $expense->spent_on->toDateString(), 'category' => $expense->category, 'split_mode' => $expense->split_mode, 'notes' => (string) $expense->notes,
        ];
        $shares = $expense->shares->keyBy('expense_member_id');
        $this->participants = $this->findGroup()->members->mapWithKeys(fn ($member) => [$member->id => $shares->has($member->id)])->all();
        $this->values = $this->findGroup()->members->mapWithKeys(fn ($member) => [$member->id => $shares->has($member->id) ? (string) ($expense->split_mode === 'amounts' ? number_format($shares[$member->id]->amount, 2, ',', '') : $shares[$member->id]->value + 0) : ($expense->split_mode === 'amounts' ? '' : '1')])->all();
        $this->editingExpenseId = $expense->id;
        $this->showForm = true;
        $this->tab = 'expenses';
    }

    public function saveExpense(): void
    {
        $group = $this->findGroup();
        $this->expense['amount'] = TripPlanner::number((string) $this->expense['amount']);
        $data = $this->validate([
            'expense.title' => ['required', 'string', 'max:150'],
            'expense.amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'expense.paid_by' => ['required', Rule::in($group->members->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'expense.spent_on' => ['required', 'date'],
            'expense.category' => ['required', Rule::in(array_keys(ExpenseSplitter::categories()))],
            'expense.split_mode' => ['required', Rule::in(Expense::SPLIT_MODES)],
            'expense.notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['expense.title' => __('What for?'), 'expense.amount' => __('Amount'), 'expense.paid_by' => __('Paid by')])['expense'];

        $memberIds = ExpenseSplitter::validMemberIds($group, array_keys(array_filter($this->participants)));
        $values = collect($memberIds)->mapWithKeys(fn (int $id) => [$id => (float) TripPlanner::number((string) ($this->values[$id] ?? '1'))])->all();

        try {
            ExpenseSplitter::split((float) $data['amount'], $data['split_mode'], $values);
        } catch (InvalidArgumentException $exception) {
            $this->addError('participants', $exception->getMessage());

            return;
        }

        $attributes = [
            'title' => $data['title'], 'amount' => $data['amount'], 'paid_by' => (int) $data['paid_by'], 'spent_on' => $data['spent_on'],
            'category' => $data['category'], 'split_mode' => $data['split_mode'], 'notes' => $data['notes'] ?: null,
        ];
        $expense = $this->editingExpenseId
            ? tap($group->expenses()->findOrFail($this->editingExpenseId))->update($attributes)
            : $group->expenses()->create($attributes + ['created_by' => Auth::id()]);
        ExpenseSplitter::saveShares($expense->fresh(), $values);

        $this->dispatch('toast', message: $this->editingExpenseId ? __('Saved.') : __('Expense added.'));
        $this->cancelExpense();
    }

    public function deleteExpense(int $id): void
    {
        $this->findGroup()->expenses()->whereKey($id)->delete();
        $this->cancelExpense();
    }

    public function settle(int $from, int $to, string $amount): void
    {
        $group = $this->findGroup();
        abort_unless(count(ExpenseSplitter::validMemberIds($group, [$from, $to])) === 2 && (float) $amount > 0, 422);
        ExpenseSplitter::recordTransfer($group, $from, $to, (float) $amount, Auth::id());
        $this->dispatch('toast', message: __('Repayment recorded.'));
    }

    public function addMember(): void
    {
        $this->validate(['newMember' => ['required', 'string', 'max:80']], attributes: ['newMember' => __('Name')]);
        $group = $this->findGroup();
        $group->members()->create(['name' => trim($this->newMember), 'position' => (int) $group->members->max('position') + 1]);
        $this->newMember = '';
        $this->resetExpense();
    }

    public function renameMember(int $id, string $name): void
    {
        $name = trim(Str::limit($name, 80, ''));
        abort_if($name === '', 422);
        $this->findGroup()->members()->whereKey($id)->update(['name' => $name]);
    }

    public function removeMember(int $id): void
    {
        $group = $this->findGroup();
        $used = $group->expenses()->where('paid_by', $id)->exists() || $group->expenses()->whereHas('shares', fn ($query) => $query->where('expense_member_id', $id))->exists();

        if ($used) {
            $this->dispatch('toast', message: __('This person already has expenses and cannot be removed.'));

            return;
        }

        $group->members()->whereKey($id)->delete();
        $this->resetExpense();
    }

    public function toggleArchive(): void
    {
        $group = $this->findGroup();
        $group->update(['archived_at' => $group->archived_at ? null : now()]);
    }

    public function deleteGroup(): void
    {
        $group = $this->findGroup();
        // Group members from other households may edit, but not delete.
        abort_unless($group->household_id === $this->householdId, 403);
        $group->delete();
        $this->redirectRoute('splits.index', navigate: true);
    }

    public function createShareLink(): void
    {
        $this->findGroup()->update(['share_token' => Str::random(40)]);
    }

    public function revokeShareLink(): void
    {
        $this->findGroup()->update(['share_token' => null, 'share_expires_at' => null]);
    }

    public function setShareLinkExpiry(string $days = ''): void
    {
        abort_unless(in_array($days, ['', '7', '30', '90'], true), 422);
        $this->findGroup()->forceFill(['share_expires_at' => $days === '' ? null : now()->addDays((int) $days)])->save();
    }

    public function with(): array
    {
        $group = ExpenseGroup::with(['members', 'expenses.shares', 'expenses.payer', 'trip'])->findOrFail($this->groupId);
        $balances = ExpenseSplitter::balances($group);

        return [
            'group' => $group,
            'balances' => $balances,
            'settlements' => ExpenseSplitter::settlements($balances),
            'stats' => ExpenseSplitter::statistics($group),
            'categories' => ExpenseSplitter::categories(),
            'me' => $group->members->firstWhere('user_id', Auth::id()),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
    $names = $group->members->pluck('name', 'id');
    $maxCategory = max([0.01, ...array_values($stats['by_category'])]);
    $maxMember = max([0.01, ...array_map(fn ($row) => max($row['paid'], $row['share']), $stats['by_member'])]);
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('splits.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('Split costs') }}</a>
            <h2 class="flex w-full flex-wrap items-center gap-2 font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $group->icon }} {{ $group->title }} <x-audience-badge :model="$group" /></h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <livewire:sharing.panel type="split" :id="$group->id" :wire:key="'share-split-'.$group->id" />

            <section class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Spent in total') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($stats['total']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':amount per person on average', ['amount' => $euro($stats['per_person'])]) }}</p>
                </div>
                @if ($me)
                    @php($mine = $balances[$me->id] ?? ['paid' => 0, 'owed' => 0, 'balance' => 0])
                    <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Your balance') }}</p>
                        <p @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-emerald-600 dark:text-emerald-400' => $mine['balance'] > 0.004, 'text-red-600 dark:text-red-400' => $mine['balance'] < -0.004, 'text-gray-900 dark:text-gray-100' => abs($mine['balance']) <= 0.004])>{{ ($mine['balance'] > 0 ? '+' : '').$euro($mine['balance']) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('paid :paid · your share :share', ['paid' => $euro($mine['paid']), 'share' => $euro($mine['owed'])]) }}</p>
                    </div>
                @endif
                <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('To settle up') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ trans_choice(':count transfer|:count transfers', count($settlements)) }}</p>
                    @if ($group->trip)
                        <a href="{{ route('trips.show', $group->trip) }}" wire:navigate class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">✈️ {{ $group->trip->title }}</a>
                    @endif
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex max-w-full gap-1 overflow-x-auto rounded-lg bg-white p-1 shadow-xs ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" role="tablist">
                    @foreach (['expenses' => '🧾 '.__('Expenses'), 'balances' => '⚖️ '.__('Balances'), 'stats' => '📊 '.__('Evaluation'), 'people' => '👥 '.__('Participants')] as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['shrink-0 rounded-md px-3 py-1.5 text-sm', 'bg-indigo-600 text-white' => $tab === $key, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $tab !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                <x-primary-button type="button" wire:click="newExpense">+ {{ __('Expense') }}</x-primary-button>
            </div>

            @if ($showForm)
                <form wire:submit="saveExpense" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('What for?') }}<input type="text" wire:model="expense.title" class="{{ $input }}" placeholder="{{ __('e.g. Supermarket, dinner, fuel') }}" autofocus><x-input-error :messages="$errors->get('expense.title')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Amount') }} (€)<input type="text" inputmode="decimal" wire:model="expense.amount" class="{{ $input }}"><x-input-error :messages="$errors->get('expense.amount')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Paid by') }}
                        <select wire:model="expense.paid_by" class="{{ $input }}">
                            @foreach ($group->members as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Date') }}<input type="date" wire:model="expense.spent_on" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Category') }}
                        <select wire:model="expense.category" class="{{ $input }}">
                            @foreach ($categories as $key => $category)
                                <option value="{{ $key }}">{{ $category['icon'] }} {{ $category['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('How to split') }}
                        <select wire:model.live="expense.split_mode" class="{{ $input }}">
                            <option value="equal">{{ __('Equally') }}</option>
                            <option value="shares">{{ __('By shares (e.g. 2 for a couple)') }}</option>
                            <option value="amounts">{{ __('By amounts') }}</option>
                        </select>
                    </label>
                    <fieldset class="sm:col-span-6">
                        <legend class="text-sm text-gray-700 dark:text-gray-300">{{ __('For whom?') }}</legend>
                        <div class="mt-1 grid gap-2 sm:grid-cols-2">
                            @foreach ($group->members as $member)
                                <div wire:key="participant-{{ $member->id }}" class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                    <label class="flex min-w-0 flex-1 items-center gap-2 text-sm text-gray-800 dark:text-gray-200"><input type="checkbox" wire:model.live="participants.{{ $member->id }}" class="rounded-sm border-gray-300 text-indigo-600"> <span class="truncate">{{ $member->name }}</span></label>
                                    @if ($expense['split_mode'] !== 'equal' && ($participants[$member->id] ?? false))
                                        <input type="text" inputmode="decimal" wire:model="values.{{ $member->id }}" aria-label="{{ $expense['split_mode'] === 'amounts' ? __('Amount for :name', ['name' => $member->name]) : __('Shares for :name', ['name' => $member->name]) }}" class="w-24 rounded-md border-gray-300 py-1 text-right text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" placeholder="{{ $expense['split_mode'] === 'amounts' ? '0,00' : '1' }}">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('participants')" class="mt-1" />
                    </fieldset>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Note') }}<input type="text" wire:model="expense.notes" class="{{ $input }}"></label>
                    <div class="flex flex-wrap gap-2 sm:col-span-6">
                        <x-primary-button>{{ $editingExpenseId ? __('Save') : __('Add') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="cancelExpense">{{ __('Cancel') }}</x-secondary-button>
                        @if ($editingExpenseId)
                            <button type="button" wire:click="deleteExpense({{ $editingExpenseId }})" wire:confirm="{{ __('Delete this expense?') }}" class="ml-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                        @endif
                    </div>
                </form>
            @endif

            @if ($tab === 'expenses')
                <section class="rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                    @forelse ($group->expenses as $item)
                        <button type="button" wire:key="expense-{{ $item->id }}" wire:click="editExpense({{ $item->id }})" class="flex w-full items-center gap-3 border-b border-gray-100 px-5 py-3 text-left last:border-b-0 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/40">
                            <span class="text-xl" aria-hidden="true">{{ $item->is_transfer ? '💸' : ($categories[$item->category]['icon'] ?? '🧾') }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $item->title }}</span>
                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $item->spent_on->format('d.m.Y') }} · {{ __('paid by :name', ['name' => $item->payer?->name]) }}{{ $item->is_transfer ? '' : ' · '.__('for :names', ['names' => $item->shares->map(fn ($share) => $names[$share->expense_member_id] ?? '?')->implode(', ')]) }}</span>
                            </span>
                            <span class="shrink-0 text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($item->amount) }}</span>
                        </button>
                    @empty
                        <p class="p-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No expenses yet. Add the first one with “+ Expense”.') }}</p>
                    @endforelse
                </section>
            @elseif ($tab === 'balances')
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Balances') }}</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach ($balances as $row)
                            <li wire:key="balance-{{ $row['member']->id }}" class="flex items-center gap-3 text-sm">
                                <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $row['member']->name }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('paid :paid · share :share', ['paid' => $euro($row['paid']), 'share' => $euro($row['owed'])]) }}</span>
                                <span @class(['w-28 text-right font-semibold tabular-nums', 'text-emerald-600 dark:text-emerald-400' => $row['balance'] > 0.004, 'text-red-600 dark:text-red-400' => $row['balance'] < -0.004, 'text-gray-500' => abs($row['balance']) <= 0.004])>{{ ($row['balance'] > 0 ? '+' : '').$euro($row['balance']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('How to settle up') }}</h3>
                    @forelse ($settlements as $transfer)
                        <div wire:key="settle-{{ $transfer['from'] }}-{{ $transfer['to'] }}" class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-gray-50 px-4 py-3 text-sm dark:bg-gray-900/40">
                            <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-200"><strong>{{ $names[$transfer['from']] }}</strong> → <strong>{{ $names[$transfer['to']] }}</strong></span>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($transfer['amount']) }}</span>
                            <button type="button" wire:click="settle({{ $transfer['from'] }}, {{ $transfer['to'] }}, '{{ $transfer['amount'] }}')" wire:confirm="{{ __('Record this repayment as paid?') }}" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-500">✓ {{ __('Paid') }}</button>
                        </div>
                    @empty
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">✓ {{ __('Everything is settled.') }}</p>
                    @endforelse
                </section>
            @elseif ($tab === 'stats')
                <section class="grid gap-5 sm:grid-cols-2">
                    <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('By category') }}</h3>
                        <ul class="mt-3 space-y-2">
                            @forelse ($stats['by_category'] as $key => $sum)
                                <li class="text-sm">
                                    <div class="flex justify-between gap-2"><span class="text-gray-700 dark:text-gray-300">{{ $categories[$key]['icon'] ?? '🧾' }} {{ $categories[$key]['label'] ?? $key }}</span><span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($sum) }} <span class="text-xs text-gray-500">({{ $stats['total'] > 0 ? round($sum / $stats['total'] * 100) : 0 }} %)</span></span></div>
                                    <div class="mt-1 h-2 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-2 rounded-full bg-indigo-500" style="width: {{ round($sum / $maxCategory * 100) }}%"></div></div>
                                </li>
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No expenses yet.') }}</li>
                            @endforelse
                        </ul>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('By person') }}</h3>
                        <p class="mt-1 flex gap-3 text-xs text-gray-500 dark:text-gray-400"><span><span class="inline-block h-2 w-3 rounded-xs bg-indigo-500 align-middle"></span> {{ __('paid') }}</span><span><span class="inline-block h-2 w-3 rounded-xs bg-amber-500 align-middle"></span> {{ __('share') }}</span></p>
                        <ul class="mt-3 space-y-3">
                            @foreach ($stats['by_member'] as $row)
                                <li class="text-sm">
                                    <div class="flex justify-between gap-2"><span class="text-gray-700 dark:text-gray-300">{{ $row['name'] }}</span><span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['paid']) }} / {{ $euro($row['share']) }}</span></div>
                                    <div class="mt-1 space-y-0.5">
                                        <div class="h-2 rounded-full bg-indigo-500" style="width: {{ round($row['paid'] / $maxMember * 100) }}%"></div>
                                        <div class="h-2 rounded-full bg-amber-500" style="width: {{ round($row['share'] / $maxMember * 100) }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-xs sm:col-span-2 dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('By day') }}</h3>
                        <table class="mt-3 w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($stats['by_day'] as $day => $sum)
                                    <tr><td class="py-1.5 text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('D, d.m.Y') }}</td><td class="py-1.5 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($sum) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if ($stats['largest'])
                            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Largest expense: :title (:amount)', ['title' => $stats['largest']->title, 'amount' => $euro($stats['largest']->amount)]) }}</p>
                        @endif
                    </div>
                </section>
            @else
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Participants') }}</h3>
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($group->members as $member)
                            <li wire:key="member-{{ $member->id }}" class="flex items-center gap-3 py-2 text-sm">
                                <input type="text" value="{{ $member->name }}" x-on:change="$wire.renameMember({{ $member->id }}, $event.target.value)" aria-label="{{ __('Name') }}" class="min-w-0 flex-1 rounded-md border-transparent bg-transparent py-1 text-sm text-gray-900 hover:border-gray-300 focus:border-indigo-500 dark:text-gray-100">
                                @if ($member->user_id)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Household member') }}</span>
                                @endif
                                <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="{{ __('Remove :name from the group?', ['name' => $member->name]) }}" class="text-xs text-red-600 underline dark:text-red-400">{{ __('Remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                    <form wire:submit="addMember" class="mt-3 flex gap-2">
                        <input type="text" wire:model="newMember" class="min-w-0 flex-1 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" placeholder="{{ __('Add a person') }}" aria-label="{{ __('Name') }}">
                        <x-secondary-button type="submit">{{ __('Add') }}</x-secondary-button>
                    </form>
                    <x-input-error :messages="$errors->get('newMember')" class="mt-1" />
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🔗 {{ __('Share with the group') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Everyone with the link sees the expenses, balances and transfers – read-only, without signing in. Handy for WhatsApp groups.') }}</p>
                    @if ($group->share_token)
                        @php($shareUrl = route('splits.public', $group->share_token))
                        <div class="mt-3 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                            <input type="text" readonly value="{{ $shareUrl }}" class="min-w-0 flex-1 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" aria-label="{{ __('Link') }}">
                            <x-secondary-button type="button" x-on:click="navigator.clipboard.writeText(@js($shareUrl)); copied = true">{{ __('Copy') }}</x-secondary-button>
                            <a href="https://wa.me/?text={{ rawurlencode($group->title.': '.$shareUrl) }}" target="_blank" rel="noopener" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500">WhatsApp</a>
                            <button type="button" wire:click="revokeShareLink" class="text-sm text-red-600 underline dark:text-red-400">{{ __('Turn link off') }}</button>
                        </div>
                        <x-share-expiry class="mt-2" :expires="$group->share_expires_at" action="setShareLinkExpiry" />
                    @else
                        <x-secondary-button type="button" wire:click="createShareLink" class="mt-3">{{ __('Create link') }}</x-secondary-button>
                    @endif
                </section>

                <section class="flex flex-wrap gap-2 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <a href="{{ route('splits.export', [$group, 'csv']) }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">⬇️ {{ __('Export CSV') }}</a>
                    <a href="{{ route('splits.export', [$group, 'pdf']) }}" target="_blank" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">📄 {{ __('Summary as PDF') }}</a>
                    <button type="button" wire:click="toggleArchive" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ $group->archived_at ? __('Restore') : __('Archive') }}</button>
                    @if ($group->household_id === $householdId)
                        <button type="button" wire:click="deleteGroup" wire:confirm="{{ __('Delete the group with all expenses?') }}" class="ml-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete group') }}</button>
                    @endif
                </section>
            @endif
        </div>
    </div>
</div>
