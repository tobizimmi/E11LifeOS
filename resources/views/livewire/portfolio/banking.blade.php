<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\BankConnection;
use App\Support\Banking\BankSync;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    /** Connection and operation waiting for the PIN. */
    public ?int $pinFor = null;

    public string $pinOperation = '';

    public string $pin = '';

    /** Connection waiting for a TAN or app confirmation. */
    public ?int $tanFor = null;

    public string $tan = '';

    public bool $decoupled = false;

    public string $challenge = '';

    private function connections()
    {
        return BankConnection::where('user_id', $this->currentUser()->id);
    }

    private function findConnection(int $connectionId): BankConnection
    {
        return $this->connections()->find($connectionId) ?? abort(404);
    }

    public function newConnection(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = ['bank' => 'dkb', 'bank_name' => 'DKB', 'blz' => BankSync::BANKS['dkb']['blz'], 'fints_url' => BankSync::BANKS['dkb']['url'], 'login_name' => '', 'pin' => '', 'save_pin' => false, 'portfolio_id' => '', 'auto_sync' => false];
        $this->showForm = true;
    }

    public function editConnection(int $connectionId): void
    {
        $connection = $this->findConnection($connectionId);
        $this->resetValidation();
        $this->editingId = $connection->id;
        $this->form = ['bank' => $connection->bank, 'bank_name' => $connection->bank_name, 'blz' => $connection->blz, 'fints_url' => $connection->fints_url, 'login_name' => $connection->login_name, 'pin' => '', 'save_pin' => $connection->pin !== null, 'portfolio_id' => (string) $connection->portfolio_id, 'auto_sync' => $connection->auto_sync];
        $this->showForm = true;
    }

    public function updatedFormBank(string $bank): void
    {
        $preset = BankSync::BANKS[$bank] ?? BankSync::BANKS['custom'];
        $this->form['bank_name'] = $preset['name'];
        $this->form['blz'] = $preset['blz'];
        $this->form['fints_url'] = $preset['url'];
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function saveConnection(): void
    {
        $data = $this->validate([
            'form.bank' => ['required', 'in:'.implode(',', array_keys(BankSync::BANKS))],
            'form.bank_name' => ['required', 'string', 'max:100'],
            'form.blz' => ['required', 'digits:8'],
            'form.fints_url' => ['required', 'url:https', 'max:255'],
            'form.login_name' => ['required', 'string', 'max:150'],
            'form.pin' => ['nullable', 'string', 'max:64'],
            'form.save_pin' => ['boolean'],
            'form.portfolio_id' => ['nullable', 'integer'],
            'form.auto_sync' => ['boolean'],
        ], attributes: ['form.blz' => __('Bank code (BLZ)'), 'form.fints_url' => __('FinTS URL'), 'form.login_name' => __('Access ID')])['form'];

        $connection = $this->editingId ? $this->findConnection($this->editingId) : new BankConnection(['user_id' => $this->currentUser()->id, 'household_id' => $this->householdId]);
        $portfolioId = $data['portfolio_id'] && $this->portfolios()->whereKey($data['portfolio_id'])->exists() ? (int) $data['portfolio_id'] : null;

        $connection->fill([
            'bank' => $data['bank'],
            'bank_name' => $data['bank_name'],
            'blz' => $data['blz'],
            'fints_url' => $data['fints_url'],
            'login_name' => $data['login_name'],
            'tan_mode' => BankSync::BANKS[$data['bank']]['tan_mode'],
            'portfolio_id' => $portfolioId,
        ]);

        // The PIN is only kept when the user allows it; automatic sync needs it.
        if (! $data['save_pin']) {
            $connection->pin = null;
        } elseif (filled($data['pin'])) {
            $connection->pin = $data['pin'];
        }

        $connection->auto_sync = $data['auto_sync'] && $connection->pin !== null;
        $connection->save();
        $this->showForm = false;
    }

    public function deleteConnection(int $connectionId): void
    {
        $this->findConnection($connectionId)->delete();
        $this->showForm = false;
    }

    /**
     * "depot", "statement" or "test"; asks for the PIN when it is not saved.
     */
    public function sync(int $connectionId, string $operation, BankSync $sync): void
    {
        abort_unless(in_array($operation, ['depot', 'statement', 'test'], true), 422);
        $connection = $this->findConnection($connectionId);

        if (! $connection->pin && $this->pinFor !== $connection->id) {
            $this->pinFor = $connection->id;
            $this->pinOperation = $operation;
            $this->reset('pin');

            return;
        }

        $this->handle($connection, fn () => $sync->run($connection, $operation, $this->pin ?: null));
        $this->reset('pinFor', 'pinOperation', 'pin');
    }

    public function submitPin(BankSync $sync): void
    {
        $this->validate(['pin' => ['required', 'string', 'max:64']], attributes: ['pin' => __('PIN')]);
        $this->sync((int) $this->pinFor, $this->pinOperation, $sync);
    }

    public function cancelPrompt(): void
    {
        $this->reset('pinFor', 'pinOperation', 'pin', 'tanFor', 'tan', 'decoupled', 'challenge');
    }

    public function submitTan(BankSync $sync): void
    {
        $this->validate(['tan' => ['required', 'string', 'max:32']], attributes: ['tan' => __('TAN')]);
        $connection = $this->findConnection((int) $this->tanFor);
        $this->handle($connection, fn () => $sync->submitTan($connection, trim($this->tan)));
    }

    public function checkDecoupled(BankSync $sync): void
    {
        if (! $this->tanFor || ! $this->decoupled) {
            return;
        }

        $connection = $this->findConnection($this->tanFor);
        $this->handle($connection, fn () => $sync->checkDecoupled($connection) ?? ['status' => 'waiting']);
    }

    public function applyToPortfolio(int $connectionId, BankSync $sync): void
    {
        try {
            $count = $sync->applyToPortfolio($this->findConnection($connectionId));
            $this->dispatch('toast', message: trans_choice(':count position reconciled.|:count positions reconciled.', $count));
        } catch (RuntimeException $exception) {
            $this->addError('sync', $exception->getMessage());
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $call
     */
    private function handle(BankConnection $connection, callable $call): void
    {
        $this->resetErrorBag('sync');

        try {
            $result = $call();
        } catch (RuntimeException $exception) {
            $this->reset('tanFor', 'tan', 'decoupled', 'challenge');
            $this->addError('sync', $exception->getMessage());

            return;
        }

        if ($result['status'] === 'waiting') {
            return;
        }

        if ($result['status'] === 'tan_required') {
            $this->tanFor = $connection->id;
            $this->decoupled = (bool) $result['decoupled'];
            $this->challenge = (string) $result['challenge'];
            $this->reset('tan');

            return;
        }

        $this->reset('tanFor', 'tan', 'decoupled', 'challenge');
        $this->dispatch('toast', message: $result['message']);
    }

    public function with(BankSync $sync): array
    {
        $connections = $this->connections()->with(['portfolio', 'depotPositions'])->orderBy('bank_name')->get();

        return [
            'connections' => $connections,
            'comparisons' => $connections->filter(fn (BankConnection $connection) => $connection->depotPositions->isNotEmpty())
                ->mapWithKeys(fn (BankConnection $connection) => [$connection->id => $sync->compare($connection)]),
            'portfolioList' => $this->portfolios()->orderBy('id')->get(),
            'banks' => BankSync::BANKS,
            'productConfigured' => filled(config('services.fints.product_id')),
        ];
    }
}; ?>

@php
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 6, ',', '.'), '0'), ',');
    $statusClasses = ['success' => 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200', 'error' => 'bg-red-50 text-red-800 dark:bg-red-900/40 dark:text-red-200', 'tan_required' => 'bg-amber-50 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">📈 {{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.banking'])

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Bank sync (FinTS)') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('Fetch your securities account holdings and account bookings directly from your bank. Holdings are compared with a portfolio, bookings land in Finances. Confirm with a TAN or in your banking app.') }}</p>
                    </div>
                    <x-primary-button type="button" wire:click="newConnection">＋ {{ __('Bank connection') }}</x-primary-button>
                </div>
                @unless ($productConfigured)
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">{{ __('Most banks require a registered FinTS product ID (FINTS_PRODUCT_ID). Without it the bank may answer with error 9078.') }}</p>
                @endunless
                <x-input-error :messages="$errors->get('sync')" class="mt-3" />
            </section>

            @if ($pinFor)
                <form wire:submit="submitPin" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-5 shadow-sm ring-2 ring-indigo-200 dark:bg-gray-800 dark:ring-indigo-800">
                    <div>
                        <x-input-label for="bank-pin" :value="__('PIN for :bank', ['bank' => $connections->firstWhere('id', $pinFor)?->bank_name])" />
                        <x-text-input id="bank-pin" type="password" wire:model="pin" class="mt-1 block w-56" autocomplete="off" autofocus />
                        <x-input-error :messages="$errors->get('pin')" class="mt-1" />
                    </div>
                    <x-primary-button wire:loading.attr="disabled">{{ __('Connect') }}</x-primary-button>
                    <button type="button" wire:click="cancelPrompt" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">{{ __('Cancel') }}</button>
                    <p class="w-full text-xs text-gray-500 dark:text-gray-400">{{ __('The PIN is only used for this connection and not saved.') }}</p>
                </form>
            @endif

            @if ($tanFor)
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-2 ring-amber-200 dark:bg-gray-800 dark:ring-amber-800" @if ($decoupled) wire:poll.5s="checkDecoupled" @endif>
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🔐 {{ $decoupled ? __('Confirm in your banking app') : __('Enter TAN') }}</h3>
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $challenge }}</p>
                    @if ($decoupled)
                        <p class="mt-3 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400"><span class="inline-block h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>{{ __('Waiting for your confirmation …') }}</p>
                        <div class="mt-3 flex gap-2">
                            <x-primary-button type="button" wire:click="checkDecoupled">{{ __('I have confirmed') }}</x-primary-button>
                            <button type="button" wire:click="cancelPrompt" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">{{ __('Cancel') }}</button>
                        </div>
                    @else
                        <form wire:submit="submitTan" class="mt-3 flex flex-wrap items-end gap-2">
                            <x-text-input wire:model="tan" inputmode="numeric" autocomplete="one-time-code" class="block w-40" :placeholder="__('TAN')" aria-label="{{ __('TAN') }}" autofocus />
                            <x-primary-button wire:loading.attr="disabled">{{ __('Confirm') }}</x-primary-button>
                            <button type="button" wire:click="cancelPrompt" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">{{ __('Cancel') }}</button>
                            <x-input-error :messages="$errors->get('tan')" class="w-full" />
                        </form>
                    @endif
                </section>
            @endif

            @if ($connections->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🏦</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('No bank connection yet. Trade Republic does not offer FinTS – please use its CSV export in the import.') }}</p>
                </div>
            @endif

            @foreach ($connections as $connection)
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" wire:key="connection-{{ $connection->id }}">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">🏦 {{ $connection->bank_name }}</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $connection->login_name }} · BLZ {{ $connection->blz }}
                                @if ($connection->portfolio) · {{ __('Portfolio: :name', ['name' => $connection->portfolio->name]) }} @endif
                                @if ($connection->auto_sync) · {{ __('synced automatically') }} @endif
                                @if ($connection->last_synced_at) · {{ __('last :time', ['time' => $connection->last_synced_at->diffForHumans()]) }} @endif
                            </p>
                            @if ($connection->sync_message)
                                <p class="mt-2 inline-block rounded-lg px-2 py-1 text-xs {{ $statusClasses[$connection->sync_status] ?? 'bg-gray-100 text-gray-700' }}">{{ $connection->sync_message }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="sync({{ $connection->id }}, 'depot')" wire:loading.attr="disabled" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">📈 {{ __('Fetch holdings') }}</button>
                            <button type="button" wire:click="sync({{ $connection->id }}, 'statement')" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-sm text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">💶 {{ __('Fetch bookings') }}</button>
                            <button type="button" wire:click="editConnection({{ $connection->id }})" class="rounded-lg px-2 py-1.5 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Edit') }}">✎</button>
                        </div>
                    </div>
                    <p wire:loading wire:target="sync" class="mt-2 text-xs text-gray-500">{{ __('Connecting to the bank …') }}</p>

                    @if (isset($comparisons[$connection->id]))
                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[36rem] text-sm">
                                <thead class="text-left text-xs text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="py-1.5 pe-3 font-medium">{{ __('Security') }}</th>
                                        <th class="py-1.5 pe-3 text-end font-medium">{{ __('At the bank') }}</th>
                                        <th class="py-1.5 pe-3 text-end font-medium">{{ __('In the portfolio') }}</th>
                                        <th class="py-1.5 text-end font-medium">{{ __('Value') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach ($comparisons[$connection->id] as $row)
                                        <tr>
                                            <td class="max-w-[16rem] py-1.5 pe-3"><span class="block truncate text-gray-900 dark:text-gray-100">{{ $row['position']->name }}</span><span class="text-xs text-gray-500">{{ $row['position']->isin }}{{ $row['symbol'] ? ' · '.$row['symbol'] : '' }}</span></td>
                                            <td class="py-1.5 pe-3 text-end tabular-nums">{{ $quantity($row['position']->quantity) }}</td>
                                            <td class="py-1.5 pe-3 text-end tabular-nums {{ abs($row['difference']) > 0.000001 ? 'text-amber-700 dark:text-amber-400' : 'text-gray-600 dark:text-gray-300' }}">{{ $connection->portfolio ? $quantity($row['portfolio_quantity']) : '—' }}</td>
                                            <td class="py-1.5 text-end tabular-nums">{{ $row['position']->value !== null ? number_format($row['position']->value, 2, ',', '.').' '.$row['position']->currency : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($connection->portfolio && collect($comparisons[$connection->id])->contains(fn ($row) => abs($row['difference']) > 0.000001 && $row['symbol']))
                            <button type="button" wire:click="applyToPortfolio({{ $connection->id }})" wire:confirm="{{ __('Book transfers so that the portfolio matches the bank?') }}" class="mt-3 rounded-lg px-3 py-1.5 text-sm text-amber-800 ring-1 ring-amber-200 hover:bg-amber-50 dark:text-amber-200 dark:ring-amber-800 dark:hover:bg-gray-700">⇄ {{ __('Adopt the bank\'s quantities in :portfolio', ['portfolio' => $connection->portfolio->name]) }}</button>
                        @endif
                    @endif
                </section>
            @endforeach
        </div>
    </div>

    @if ($showForm)
        <x-sheet close="closeForm" :label="$editingId ? __('Edit bank connection') : __('New bank connection')" wire:key="bank-form-{{ $editingId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">🏦 {{ $editingId ? __('Edit bank connection') : __('New bank connection') }}</strong></x-slot:header>

            <form id="bank-form" wire:submit="saveConnection" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="bank-preset" :value="__('Bank')" />
                    <select id="bank-preset" wire:model.live="form.bank" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        @foreach ($banks as $key => $bank)
                            <option value="{{ $key }}">{{ $bank['name'] ?: __('Other bank') }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __($banks[$form['bank']]['hint'] ?? '') }}</p>
                </div>
                <div>
                    <x-input-label for="bank-name" :value="__('Name')" />
                    <x-text-input id="bank-name" wire:model="form.bank_name" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('form.bank_name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="bank-blz" :value="__('Bank code (BLZ)')" />
                    <x-text-input id="bank-blz" wire:model="form.blz" inputmode="numeric" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('form.blz')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="bank-url" :value="__('FinTS URL')" />
                    <x-text-input id="bank-url" wire:model="form.fints_url" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('form.fints_url')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="bank-login" :value="__('Access ID')" />
                    <x-text-input id="bank-login" wire:model="form.login_name" class="mt-1 block w-full" autocomplete="off" />
                    <x-input-error :messages="$errors->get('form.login_name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="bank-portfolio" :value="__('Compare holdings with portfolio')" />
                    <select id="bank-portfolio" wire:model="form.portfolio_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">—</option>
                        @foreach ($portfolioList as $portfolio)
                            <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-start gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" wire:model.live="form.save_pin" class="mt-0.5 rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                    <span>{{ __('Save PIN encrypted') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('Needed for the automatic daily sync. Otherwise you enter the PIN each time.') }}</span></span>
                </label>
                @if ($form['save_pin'])
                    <div>
                        <x-input-label for="bank-save-pin" :value="__('PIN')" />
                        <x-text-input id="bank-save-pin" type="password" wire:model="form.pin" class="mt-1 block w-full" autocomplete="new-password" :placeholder="$editingId ? __('unchanged') : ''" />
                    </div>
                    <label class="flex items-center gap-2 pt-6 text-sm">
                        <input type="checkbox" wire:model="form.auto_sync" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Sync automatically every morning') }}
                    </label>
                @endif
            </form>

            <x-slot:footer>
                <x-primary-button form="bank-form">{{ __('Save') }}</x-primary-button>
                @if ($editingId)
                    <button type="button" wire:click="deleteConnection({{ $editingId }})" wire:confirm="{{ __('Delete this bank connection? Imported bookings stay.') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
