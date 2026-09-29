<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Kid;
use App\Models\KidChore;
use App\Models\KidChoreCompletion;
use App\Models\KidTransaction;
use App\Support\Life\PocketMoney;
use App\Support\Life\TripPlanner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Pocket money and chores of the children: each child sees today's chores and ticks them off,
 * parents confirm, the reward and the weekly allowance land on the child's pocket money account.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /** kid, chore or money */
    public ?string $sheet = null;

    public ?int $editingId = null;

    /**
     * @var array{name: string, emoji: string, birth_date: string, weekly_allowance: string, allowance_day: int, auto_approve: bool, goal_title: string, goal_amount: string}
     */
    public array $kid = [];

    /**
     * @var array{title: string, emoji: string, reward: string, frequency: string, kid_id: string, active: bool}
     */
    public array $chore = [];

    /**
     * @var array{kid_id: int, kind: string, amount: string, label: string}
     */
    public array $money = [];

    public ?int $historyFor = null;

    public function mount(): void
    {
        $this->resolveHousehold();
    }

    private function findKid(int $id): Kid
    {
        return Kid::where('household_id', $this->householdId)->find($id) ?? abort(404);
    }

    private function findChore(int $id): KidChore
    {
        return KidChore::where('household_id', $this->householdId)->find($id) ?? abort(404);
    }

    public function closeSheet(): void
    {
        $this->reset('sheet', 'editingId');
        $this->resetValidation();
    }

    public function editKid(?int $id = null): void
    {
        $kid = $id ? $this->findKid($id) : null;
        $this->kid = [
            'name' => (string) $kid?->name, 'emoji' => $kid?->emoji ?? '🧒', 'birth_date' => (string) $kid?->birth_date?->toDateString(),
            'weekly_allowance' => $kid?->weekly_allowance !== null ? number_format($kid->weekly_allowance, 2, ',', '') : '',
            'allowance_day' => $kid?->allowance_day ?? 6, 'auto_approve' => (bool) $kid?->auto_approve,
            'goal_title' => (string) $kid?->goal_title, 'goal_amount' => $kid?->goal_amount !== null ? number_format($kid->goal_amount, 2, ',', '') : '',
        ];
        $this->editingId = $kid?->id;
        $this->sheet = 'kid';
    }

    public function saveKid(): void
    {
        $this->kid['weekly_allowance'] = TripPlanner::number((string) $this->kid['weekly_allowance']);
        $this->kid['goal_amount'] = TripPlanner::number((string) $this->kid['goal_amount']);
        $data = $this->validate([
            'kid.name' => ['required', 'string', 'max:60'],
            'kid.emoji' => ['required', 'string', 'max:16'],
            'kid.birth_date' => ['nullable', 'date', 'before:today'],
            'kid.weekly_allowance' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'kid.allowance_day' => ['required', 'integer', 'between:1,7'],
            'kid.auto_approve' => ['boolean'],
            'kid.goal_title' => ['nullable', 'string', 'max:100'],
            'kid.goal_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], attributes: ['kid.name' => __('Name'), 'kid.weekly_allowance' => __('Pocket money per week')])['kid'];

        $values = [
            'name' => $data['name'], 'emoji' => $data['emoji'], 'birth_date' => $data['birth_date'] ?: null,
            'weekly_allowance' => $data['weekly_allowance'] !== '' ? $data['weekly_allowance'] : null, 'allowance_day' => (int) $data['allowance_day'],
            'auto_approve' => (bool) $data['auto_approve'], 'goal_title' => $data['goal_title'] ?: null, 'goal_amount' => $data['goal_amount'] !== '' ? $data['goal_amount'] : null,
        ];

        if ($this->editingId) {
            $this->findKid($this->editingId)->update($values);
        } else {
            Kid::create($values + ['household_id' => $this->householdId, 'last_allowance_on' => today()->toDateString()]);
        }

        $this->closeSheet();
    }

    public function deleteKid(int $id): void
    {
        $this->findKid($id)->delete();
        $this->closeSheet();
    }

    public function editChore(?int $id = null): void
    {
        $chore = $id ? $this->findChore($id) : null;
        $this->chore = [
            'title' => (string) $chore?->title, 'emoji' => $chore?->emoji ?? '⭐', 'reward' => $chore ? number_format($chore->reward, 2, ',', '') : '0,50',
            'frequency' => $chore?->frequency ?? 'weekly', 'kid_id' => (string) $chore?->kid_id, 'active' => $chore?->active ?? true,
        ];
        $this->editingId = $chore?->id;
        $this->sheet = 'chore';
    }

    public function saveChore(): void
    {
        $this->chore['reward'] = TripPlanner::number((string) $this->chore['reward']);
        $data = $this->validate([
            'chore.title' => ['required', 'string', 'max:120'],
            'chore.emoji' => ['required', 'string', 'max:16'],
            'chore.reward' => ['required', 'numeric', 'min:0', 'max:1000'],
            'chore.frequency' => ['required', Rule::in(KidChore::FREQUENCIES)],
            'chore.kid_id' => ['nullable', Rule::exists('kids', 'id')->where('household_id', $this->householdId)],
            'chore.active' => ['boolean'],
        ], attributes: ['chore.title' => __('Chore'), 'chore.reward' => __('Reward (€)')])['chore'];

        $values = ['title' => $data['title'], 'emoji' => $data['emoji'], 'reward' => $data['reward'], 'frequency' => $data['frequency'], 'kid_id' => $data['kid_id'] ?: null, 'active' => (bool) $data['active']];

        if ($this->editingId) {
            $this->findChore($this->editingId)->update($values);
        } else {
            KidChore::create($values + ['household_id' => $this->householdId]);
        }

        $this->closeSheet();
    }

    public function deleteChore(int $id): void
    {
        $this->findChore($id)->delete();
        $this->closeSheet();
    }

    public function done(int $kidId, int $choreId): void
    {
        $kid = $this->findKid($kidId);
        $chore = $this->findChore($choreId);
        abort_unless($chore->isOpenFor($kid, today()), 422);

        PocketMoney::complete($kid, $chore, Auth::user());
        $this->dispatch('toast', message: $kid->auto_approve ? __('Well done! :amount credited.', ['amount' => number_format($chore->reward, 2, ',', '.').' €']) : __('Well done! A parent confirms it.'));
    }

    private function findCompletion(int $id): KidChoreCompletion
    {
        return KidChoreCompletion::whereIn('kid_id', Kid::where('household_id', $this->householdId)->select('id'))->find($id) ?? abort(404);
    }

    public function approve(int $completionId): void
    {
        PocketMoney::approve($this->findCompletion($completionId), Auth::user());
    }

    public function reject(int $completionId): void
    {
        PocketMoney::reject($this->findCompletion($completionId), Auth::user());
    }

    public function openMoney(int $kidId, string $kind): void
    {
        abort_unless(in_array($kind, ['bonus', 'gift', 'spend'], true), 422);
        $this->findKid($kidId);
        $this->money = ['kid_id' => $kidId, 'kind' => $kind, 'amount' => '', 'label' => ''];
        $this->sheet = 'money';
    }

    public function saveMoney(): void
    {
        $this->money['amount'] = TripPlanner::number((string) $this->money['amount']);
        $data = $this->validate([
            'money.kind' => ['required', Rule::in(['bonus', 'gift', 'spend'])],
            'money.amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'money.label' => ['required', 'string', 'max:120'],
        ], attributes: ['money.amount' => __('Amount (€)'), 'money.label' => __('What for?')])['money'];

        $kid = $this->findKid((int) $this->money['kid_id']);
        KidTransaction::create([
            'kid_id' => $kid->id, 'date' => today()->toDateString(), 'kind' => $data['kind'], 'label' => $data['label'],
            'amount' => $data['kind'] === 'spend' ? -$data['amount'] : $data['amount'], 'created_by' => Auth::id(),
        ]);
        $this->closeSheet();
    }

    public function deleteTransaction(int $id): void
    {
        $transaction = KidTransaction::whereIn('kid_id', Kid::where('household_id', $this->householdId)->select('id'))->whereNull('kid_chore_completion_id')->find($id) ?? abort(404);
        $transaction->delete();
    }

    public function with(): array
    {
        $kids = Kid::where('household_id', $this->householdId)->orderBy('birth_date')->orderBy('name')->get();
        $chores = KidChore::where('household_id', $this->householdId)->orderByDesc('active')->orderBy('title')->get();
        $activeChores = $chores->where('active', true);
        $balances = KidTransaction::whereIn('kid_id', $kids->pluck('id'))->selectRaw('kid_id, SUM(amount) as total')->groupBy('kid_id')->pluck('total', 'kid_id');

        return [
            'kids' => $kids->map(fn (Kid $kid) => [
                'kid' => $kid,
                'balance' => round((float) ($balances[$kid->id] ?? 0), 2),
                'open' => PocketMoney::openChores($kid, $activeChores),
                'goal' => PocketMoney::goalProgress($kid, (float) ($balances[$kid->id] ?? 0)),
                'history' => $this->historyFor === $kid->id ? $kid->transactions()->limit(30)->get() : collect(),
            ]),
            'chores' => $chores,
            'pending' => KidChoreCompletion::whereIn('kid_id', $kids->pluck('id'))->where('status', KidChoreCompletion::PENDING)->with(['chore', 'kid'])->orderBy('done_on')->get(),
            'frequencies' => ['daily' => __('daily'), 'weekly' => __('once a week'), 'once' => __('once')],
            'weekdays' => collect(range(1, 7))->mapWithKeys(fn (int $day) => [$day => today()->startOfWeek()->addDays($day - 1)->translatedFormat('l')])->all(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
    $kindLabels = ['allowance' => '💰 '.__('Pocket money'), 'chore' => '⭐ '.__('Chore'), 'bonus' => '🎁 '.__('Bonus'), 'gift' => '🎀 '.__('Gift'), 'spend' => '🛍️ '.__('Spent')];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">⭐ {{ __('Kids & pocket money') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap justify-end gap-2">
                <x-secondary-button type="button" wire:click="editChore">+ {{ __('Chore') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="editKid">+ {{ __('Child') }}</x-primary-button>
            </div>

            @if ($pending->isNotEmpty())
                <section class="rounded-2xl bg-amber-50 p-5 shadow-sm ring-1 ring-amber-200 dark:bg-amber-900/20 dark:ring-amber-800">
                    <h3 class="font-semibold text-amber-900 dark:text-amber-200">🔔 {{ __('Please confirm') }}</h3>
                    <ul class="mt-2 divide-y divide-amber-200/60 dark:divide-amber-800/60">
                        @foreach ($pending as $completion)
                            <li wire:key="pending-{{ $completion->id }}" class="flex flex-wrap items-center gap-2 py-2 text-sm">
                                <span class="min-w-0 flex-1 text-gray-900 dark:text-gray-100">{{ $completion->kid->emoji }} <strong>{{ $completion->kid->name }}</strong>: {{ $completion->chore->emoji }} {{ $completion->chore->title }} <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $completion->done_on->translatedFormat('D, j. M') }} · {{ $euro($completion->reward) }}</span></span>
                                <button type="button" wire:click="approve({{ $completion->id }})" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">✓ {{ __('Confirm') }}</button>
                                <button type="button" wire:click="reject({{ $completion->id }})" class="rounded-lg px-3 py-1.5 text-xs text-gray-700 ring-1 ring-gray-300 hover:bg-white dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700">{{ __('Not done') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($kids->isEmpty())
                <section class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🧒</p>
                    <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No children added yet.') }}</p>
                    <p class="mx-auto mt-1 max-w-lg text-sm text-gray-500 dark:text-gray-400">{{ __('Children do not need their own login: they tick off their chores on your phone or tablet, you confirm, and the reward lands on their pocket money account – together with the weekly pocket money and a savings goal.') }}</p>
                </section>
            @endif

            <div class="grid gap-5 md:grid-cols-2">
                @foreach ($kids as $row)
                    @php($child = $row['kid'])
                    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" wire:key="kid-{{ $child->id }}">
                        <div class="flex items-start gap-3">
                            <span class="text-4xl" aria-hidden="true">{{ $child->emoji }}</span>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $child->name }}</h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ collect([$child->age() !== null ? trans_choice(':count year|:count years', $child->age()) : null, $child->weekly_allowance ? __(':amount every :day', ['amount' => $euro($child->weekly_allowance), 'day' => $weekdays[$child->allowance_day]]) : null])->filter()->implode(' · ') }}</p>
                            </div>
                            <button type="button" wire:click="editKid({{ $child->id }})" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" aria-label="{{ __('Edit :name', ['name' => $child->name]) }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                        </div>

                        <div class="mt-4 flex items-end justify-between gap-3">
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Pocket money account') }}</p>
                                <p @class(['text-3xl font-bold tabular-nums', 'text-red-600 dark:text-red-400' => $row['balance'] < 0, 'text-gray-900 dark:text-gray-100' => $row['balance'] >= 0])>{{ $euro($row['balance']) }}</p>
                            </div>
                            <div class="flex flex-wrap justify-end gap-1">
                                <button type="button" wire:click="openMoney({{ $child->id }}, 'bonus')" class="rounded-lg px-2 py-1 text-xs text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-800 dark:hover:bg-gray-700">+ {{ __('Bonus') }}</button>
                                <button type="button" wire:click="openMoney({{ $child->id }}, 'gift')" class="rounded-lg px-2 py-1 text-xs text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-800 dark:hover:bg-gray-700">+ {{ __('Gift') }}</button>
                                <button type="button" wire:click="openMoney({{ $child->id }}, 'spend')" class="rounded-lg px-2 py-1 text-xs text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-700">− {{ __('Spent') }}</button>
                            </div>
                        </div>

                        @if ($row['goal'] !== null)
                            <div class="mt-3">
                                <div class="flex justify-between text-xs text-gray-600 dark:text-gray-300"><span>🎯 {{ $child->goal_title ?: __('Savings goal') }}</span><span class="tabular-nums">{{ $euro(max(0, $row['balance'])) }} / {{ $euro($child->goal_amount) }}</span></div>
                                <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ (int) $row['goal'] }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $row['goal'] }}%"></div></div>
                                @if ($row['goal'] >= 100)<p class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">🎉 {{ __('Goal reached!') }}</p>@endif
                            </div>
                        @endif

                        <h4 class="mt-4 text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('Today to do') }}</h4>
                        @if ($row['open']->isEmpty())
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('All done – great!') }}</p>
                        @else
                            <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($row['open'] as $task)
                                    <li wire:key="open-{{ $child->id }}-{{ $task->id }}">
                                        <button type="button" wire:click="done({{ $child->id }}, {{ $task->id }})" class="flex w-full items-center gap-2 rounded-xl bg-indigo-50 px-3 py-3 text-left text-sm text-indigo-900 transition hover:bg-indigo-100 active:scale-[0.98] dark:bg-indigo-900/30 dark:text-indigo-100 dark:hover:bg-indigo-900/50">
                                            <span class="text-xl" aria-hidden="true">{{ $task->emoji }}</span>
                                            <span class="min-w-0 flex-1">{{ $task->title }}</span>
                                            @if ($task->reward > 0)<span class="shrink-0 text-xs font-medium tabular-nums">+{{ $euro($task->reward) }}</span>@endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <button type="button" wire:click="$set('historyFor', {{ $historyFor === $child->id ? 'null' : $child->id }})" class="mt-4 text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ $historyFor === $child->id ? __('Hide history') : __('Show history') }}</button>
                        @if ($historyFor === $child->id)
                            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                                @forelse ($row['history'] as $booking)
                                    <li wire:key="booking-{{ $booking->id }}" class="flex items-center gap-2 py-1.5">
                                        <span class="w-16 shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $booking->date->format('d.m.') }}</span>
                                        <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $booking->label }} <span class="text-xs text-gray-500 dark:text-gray-400">{{ $kindLabels[$booking->kind] ?? '' }}</span></span>
                                        <span @class(['tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $booking->amount > 0, 'text-gray-900 dark:text-gray-100' => $booking->amount <= 0])>{{ $booking->amount > 0 ? '+' : '' }}{{ $euro($booking->amount) }}</span>
                                        @if (! $booking->kid_chore_completion_id)
                                            <button type="button" wire:click="deleteTransaction({{ $booking->id }})" wire:confirm="{{ __('Delete this booking?') }}" class="rounded p-1 text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}">✕</button>
                                        @endif
                                    </li>
                                @empty
                                    <li class="py-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('No bookings yet.') }}</li>
                                @endforelse
                            </ul>
                        @endif
                    </section>
                @endforeach
            </div>

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">📋 {{ __('Chores') }}</h3>
                @if ($chores->isEmpty())
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Add chores like “Tidy your room”, “Empty the dishwasher” or “Take out the rubbish” – with a small reward.') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($chores as $task)
                            <li wire:key="chore-{{ $task->id }}">
                                <button type="button" wire:click="editChore({{ $task->id }})" @class(['flex w-full flex-wrap items-center gap-x-3 gap-y-1 py-2 text-left text-sm', 'opacity-50' => ! $task->active])>
                                    <span class="text-lg" aria-hidden="true">{{ $task->emoji }}</span>
                                    <span class="min-w-0 flex-1 text-gray-900 dark:text-gray-100">{{ $task->title }}<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $frequencies[$task->frequency] ?? '' }} · {{ $task->kid_id ? $kids->firstWhere('kid.id', $task->kid_id)['kid']->name ?? '' : __('all children') }}{{ $task->active ? '' : ' · '.__('paused') }}</span></span>
                                    <span class="tabular-nums text-gray-700 dark:text-gray-300">{{ $euro($task->reward) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

    @if ($sheet === 'kid')
        <x-sheet close="closeSheet" :label="__('Child')" size="md">
            <x-slot name="header"><h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $editingId ? __('Edit child') : __('New child') }}</h3></x-slot>
            <form wire:submit="saveKid" id="kid-form" class="grid gap-3 p-5 sm:grid-cols-4">
                <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="kid.name" class="{{ $input }}"><x-input-error :messages="$errors->get('kid.name')" /></label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Emoji') }}<input type="text" wire:model="kid.emoji" class="{{ $input }} text-center"></label>
                <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Birthday') }}<input type="date" wire:model="kid.birth_date" class="{{ $input }}"><x-input-error :messages="$errors->get('kid.birth_date')" /></label>
                <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Pocket money per week') }}<input type="text" inputmode="decimal" wire:model="kid.weekly_allowance" class="{{ $input }}" placeholder="{{ __('none') }}"><x-input-error :messages="$errors->get('kid.weekly_allowance')" /></label>
                <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('Paid out on') }}
                    <select wire:model="kid.allowance_day" class="{{ $input }}">
                        @foreach ($weekdays as $day => $name)
                            <option value="{{ $day }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Savings goal') }}<input type="text" wire:model="kid.goal_title" class="{{ $input }}" placeholder="{{ __('e.g. Lego set') }}"></label>
                <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Goal amount (€)') }}<input type="text" inputmode="decimal" wire:model="kid.goal_amount" class="{{ $input }}"><x-input-error :messages="$errors->get('kid.goal_amount')" /></label>
                <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-4 dark:text-gray-300"><input type="checkbox" wire:model="kid.auto_approve" class="rounded border-gray-300 text-indigo-600"> {{ __('Credit done chores without confirmation') }}</label>
            </form>
            <x-slot name="footer">
                <div class="flex items-center gap-2">
                    <x-primary-button form="kid-form">{{ __('Save') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeSheet">{{ __('Cancel') }}</x-secondary-button>
                    @if ($editingId)
                        <button type="button" wire:click="deleteKid({{ $editingId }})" wire:confirm="{{ __('Delete this child with the pocket money account?') }}" class="ms-auto text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="h-5 w-5" /></button>
                    @endif
                </div>
            </x-slot>
        </x-sheet>
    @elseif ($sheet === 'chore')
        <x-sheet close="closeSheet" :label="__('Chore')" size="md">
            <x-slot name="header"><h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $editingId ? __('Edit chore') : __('New chore') }}</h3></x-slot>
            <form wire:submit="saveChore" id="chore-form" class="grid gap-3 p-5 sm:grid-cols-4">
                <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Chore') }}<input type="text" wire:model="chore.title" class="{{ $input }}" placeholder="{{ __('e.g. Tidy your room') }}"><x-input-error :messages="$errors->get('chore.title')" /></label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Emoji') }}<input type="text" wire:model="chore.emoji" class="{{ $input }} text-center"></label>
                <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Reward (€)') }}<input type="text" inputmode="decimal" wire:model="chore.reward" class="{{ $input }}"><x-input-error :messages="$errors->get('chore.reward')" /></label>
                <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('How often') }}
                    <select wire:model="chore.frequency" class="{{ $input }}">
                        @foreach ($frequencies as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('For') }}
                    <select wire:model="chore.kid_id" class="{{ $input }}">
                        <option value="">{{ __('all children') }}</option>
                        @foreach ($kids as $row)
                            <option value="{{ $row['kid']->id }}">{{ $row['kid']->emoji }} {{ $row['kid']->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-4 dark:text-gray-300"><input type="checkbox" wire:model="chore.active" class="rounded border-gray-300 text-indigo-600"> {{ __('Active') }}</label>
            </form>
            <x-slot name="footer">
                <div class="flex items-center gap-2">
                    <x-primary-button form="chore-form">{{ __('Save') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeSheet">{{ __('Cancel') }}</x-secondary-button>
                    @if ($editingId)
                        <button type="button" wire:click="deleteChore({{ $editingId }})" wire:confirm="{{ __('Delete this chore?') }}" class="ms-auto text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="h-5 w-5" /></button>
                    @endif
                </div>
            </x-slot>
        </x-sheet>
    @elseif ($sheet === 'money')
        <x-sheet close="closeSheet" :label="$kindLabels[$money['kind']] ?? ''" size="md">
            <x-slot name="header"><h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $kindLabels[$money['kind']] ?? '' }}</h3></x-slot>
            <form wire:submit="saveMoney" id="money-form" class="grid gap-3 p-5 sm:grid-cols-3">
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Amount (€)') }}<input type="text" inputmode="decimal" wire:model="money.amount" class="{{ $input }}" autofocus><x-input-error :messages="$errors->get('money.amount')" /></label>
                <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('What for?') }}<input type="text" wire:model="money.label" class="{{ $input }}" placeholder="{{ $money['kind'] === 'spend' ? __('e.g. Comic, ice cream') : __('e.g. Good school report, birthday money') }}"><x-input-error :messages="$errors->get('money.label')" /></label>
            </form>
            <x-slot name="footer">
                <div class="flex items-center gap-2">
                    <x-primary-button form="money-form">{{ __('Book') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeSheet">{{ __('Cancel') }}</x-secondary-button>
                </div>
            </x-slot>
        </x-sheet>
    @endif
</div>
