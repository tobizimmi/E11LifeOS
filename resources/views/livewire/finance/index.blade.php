<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\BankAccount;
use App\Models\FinanceRule;
use App\Models\Transaction;
use App\Support\AiException;
use App\Support\Finance\BankCsvImporter;
use App\Support\Finance\Categorizer;
use App\Support\Finance\FinanceAi;
use App\Support\Finance\FinanceCategories;
use App\Support\Finance\FinancePeriod;
use App\Support\Finance\FinanceReport;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use WithFileUploads;

    private const TRANSACTIONS_PER_PAGE = 100;

    #[Url(except: 'report')]
    public string $tab = 'report';

    #[Url(except: FinancePeriod::DEFAULT)]
    public string $period = FinancePeriod::DEFAULT;

    #[Url(as: 'account', except: '')]
    public string $accountFilter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'category', except: '')]
    public string $categoryFilter = '';

    public int $limit = self::TRANSACTIONS_PER_PAGE;

    public $statement = null;

    public string $importAccount = 'new';

    public string $newAccountName = '';

    /**
     * @var array{imported: int, skipped: int, errors: int}|null
     */
    public ?array $importResult = null;

    public ?string $importError = null;

    /**
     * The AI analyst's assessment of the current report (not stored).
     *
     * @var array<string, mixed>|null
     */
    public ?array $advice = null;

    public ?string $aiError = null;

    public function mount(): void
    {
        if (! array_key_exists($this->period, FinancePeriod::options())) {
            $this->period = FinancePeriod::DEFAULT;
        }

        $this->resolveHousehold();
        $firstAccountId = $this->accounts()->value('id');

        if ($firstAccountId) {
            $this->importAccount = (string) $firstAccountId;
        }
    }

    private function accounts()
    {
        return BankAccount::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)->orderBy('name');
    }

    private function accountId(): ?int
    {
        return $this->accountFilter !== '' && ctype_digit($this->accountFilter) ? (int) $this->accountFilter : null;
    }

    private function findTransaction(int $id): Transaction
    {
        return Transaction::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)->find($id) ?? abort(404);
    }

    public function updatedPeriod(): void
    {
        $this->advice = null;
    }

    public function updatedAccountFilter(): void
    {
        $this->advice = null;
    }

    // ── Import ───────────────────────────────────────────────────────────

    public function importStatement(BankCsvImporter $importer): void
    {
        $this->reset('importResult', 'importError');
        $this->validate([
            'statement' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'importAccount' => ['required', 'string'],
            'newAccountName' => [Rule::requiredIf($this->importAccount === 'new'), 'nullable', 'string', 'max:100'],
        ], attributes: ['newAccountName' => __('Account name')]);

        $account = $this->importAccount === 'new'
            ? BankAccount::create(['household_id' => $this->householdId, 'user_id' => $this->currentUser()->id, 'name' => trim($this->newAccountName)])
            : ($this->accounts()->find((int) $this->importAccount) ?? abort(404));

        try {
            $this->importResult = $importer->import($account, (string) file_get_contents($this->statement->getRealPath()), new Categorizer($this->currentUser()->id));
        } catch (RuntimeException $exception) {
            $this->importError = $exception->getMessage();

            if ($this->importAccount === 'new' && $account->transactions()->doesntExist()) {
                $account->delete();
            }

            return;
        }

        $this->importAccount = (string) $account->id;
        $this->reset('statement', 'newAccountName', 'advice');
    }

    // ── Bookings ─────────────────────────────────────────────────────────

    /**
     * Changes a booking's category; with $remember all bookings of this payee (now and in
     * future imports) get the category too.
     */
    public function setCategory(int $transactionId, string $category, bool $remember = false): void
    {
        abort_unless(FinanceCategories::exists($category), 422);

        $transaction = $this->findTransaction($transactionId);
        $transaction->update(['category' => $category, 'category_locked' => true]);

        if ($remember) {
            $pattern = mb_substr(FinanceReport::payeeKey($transaction->counterparty, $transaction->purpose), 0, 100);

            if ($pattern !== '—') {
                FinanceRule::updateOrCreate(['user_id' => $this->currentUser()->id, 'pattern' => $pattern], ['category' => $category]);

                Transaction::where('user_id', $this->currentUser()->id)->where('category_locked', false)
                    ->where(fn ($query) => $query->whereRaw('LOWER(counterparty) LIKE ?', ['%'.$pattern.'%'])->orWhereRaw('LOWER(purpose) LIKE ?', ['%'.$pattern.'%']))
                    ->update(['category' => $category]);

                $this->dispatch('toast', message: __('Remembered: “:pattern” is now always :category.', ['pattern' => $pattern, 'category' => FinanceCategories::label($category)]));
            }
        }

        $this->advice = null;
    }

    public function deleteRule(int $ruleId): void
    {
        FinanceRule::where('user_id', $this->currentUser()->id)->whereKey($ruleId)->delete();
    }

    /**
     * Lets the AI categorize the bookings still marked "other".
     */
    public function categorizeWithAi(FinanceAi $ai): void
    {
        $this->aiError = null;
        $open = Transaction::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)
            ->where('category_locked', false)->whereIn('category', [FinanceCategories::OTHER, FinanceCategories::OTHER_INCOME])
            ->latest('booked_on')->limit(400)->get();

        if ($open->isEmpty()) {
            $this->dispatch('toast', message: __('All bookings already have a category.'));

            return;
        }

        try {
            $categories = $ai->categorize($open->map(fn (Transaction $transaction) => [
                'id' => $transaction->id, 'counterparty' => $transaction->counterparty, 'purpose' => mb_substr((string) $transaction->purpose, 0, 140), 'amount' => $transaction->amount,
            ])->values()->all());
        } catch (AiException $exception) {
            $this->aiError = $exception->getMessage();

            return;
        }

        $changed = 0;

        foreach ($open as $transaction) {
            $category = $categories[$transaction->id] ?? null;

            if ($category && $category !== $transaction->category && (($category === FinanceCategories::TRANSFER) || (FinanceCategories::group($category) === 'income') === ($transaction->amount >= 0))) {
                $transaction->update(['category' => $category]);
                $changed++;
            }
        }

        $this->advice = null;
        $this->dispatch('toast', message: trans_choice(':count booking categorized|:count bookings categorized', $changed));
    }

    public function deleteAccount(int $accountId): void
    {
        ($this->accounts()->find($accountId) ?? abort(404))->delete();
        $this->accountFilter = '';
        $this->advice = null;
    }

    public function renameAccount(int $accountId, string $name): void
    {
        $name = trim($name);

        if ($name !== '') {
            ($this->accounts()->find($accountId) ?? abort(404))->update(['name' => mb_substr($name, 0, 100)]);
        }
    }

    // ── Report ───────────────────────────────────────────────────────────

    public function askAnalyst(FinanceAi $ai): void
    {
        $this->aiError = null;
        [$from, $to] = FinancePeriod::range($this->period);
        $report = FinanceReport::build(FinancePeriod::query($this->currentUser(), (int) $this->householdId, $this->period, $this->accountId())->get(), $from, $to);

        if ($report['count'] === 0) {
            return;
        }

        try {
            $this->advice = $ai->advise($report);
        } catch (AiException $exception) {
            $this->aiError = $exception->getMessage();
        }
    }

    public function with(): array
    {
        $accounts = $this->accounts()->withCount('transactions')->get();
        $data = [
            'accounts' => $accounts,
            'periods' => FinancePeriod::options(),
            'categories' => FinanceCategories::all(),
            'aiAvailable' => FinanceAi::isConfigured(),
        ];

        if ($this->tab === 'transactions') {
            $query = FinancePeriod::query($this->currentUser(), (int) $this->householdId, $this->period, $this->accountId())
                ->when($this->categoryFilter !== '', fn ($query) => $query->where('category', $this->categoryFilter))
                ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereRaw('LOWER(counterparty) LIKE ?', ['%'.mb_strtolower(trim($this->search)).'%'])
                    ->orWhereRaw('LOWER(purpose) LIKE ?', ['%'.mb_strtolower(trim($this->search)).'%'])));

            $data['transactionCount'] = (clone $query)->count();
            $data['transactions'] = $query->with('account')->orderByDesc('booked_on')->orderByDesc('id')->limit($this->limit)->get();
            $data['rules'] = FinanceRule::where('user_id', $this->currentUser()->id)->orderBy('pattern')->get();
        }

        if ($this->tab === 'report') {
            [$from, $to] = FinancePeriod::range($this->period);
            $data['report'] = FinanceReport::build(FinancePeriod::query($this->currentUser(), (int) $this->householdId, $this->period, $this->accountId())->get(), $from, $to);
            $data['openCount'] = Transaction::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)->whereIn('category', [FinanceCategories::OTHER, FinanceCategories::OTHER_INCOME])->where('category_locked', false)->count();
        }

        return $data;
    }
}; ?>

@php
    $euro = fn ($value, $decimals = 2) => number_format((float) $value, $decimals, ',', '.').' €';
    $frequencies = ['weekly' => __('weekly'), 'monthly' => __('monthly'), 'bimonthly' => __('every 2 months'), 'quarterly' => __('quarterly'), 'halfyearly' => __('half-yearly'), 'yearly' => __('yearly')];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">💶 {{ __('Finances') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your bank data.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            {{-- Tabs + filters --}}
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex rounded-lg bg-white p-1 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" role="tablist">
                    @foreach (['report' => '📊 '.__('Report'), 'transactions' => '📄 '.__('Bookings'), 'import' => '⬆ '.__('Import')] as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['rounded-md px-3 py-1.5 text-sm', 'bg-indigo-600 text-white' => $tab === $key, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $tab !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                @if ($tab !== 'import')
                    <select wire:model.live="period" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Period') }}">
                        @foreach ($periods as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($accounts->count() > 1)
                        <select wire:model.live="accountFilter" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Account') }}">
                            <option value="">{{ __('All accounts') }}</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    @endif
                @endif
            </div>

            @if ($accounts->isEmpty() && $tab !== 'import')
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🏦</p>
                    <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Import your first bank statement') }}</h3>
                    <p class="mx-auto mt-1 max-w-lg text-sm text-gray-600 dark:text-gray-400">{{ __('Export the transactions of your account as CSV in online banking (Sparkasse, ING, DKB, comdirect, N26, Volksbank …) and import the file here. The analysis then shows where your money goes and where you can save.') }}</p>
                    <x-primary-button type="button" wire:click="$set('tab', 'import')" class="mt-4">⬆ {{ __('Import statement') }}</x-primary-button>
                </div>
            @elseif ($tab === 'report')
                @include('finance.partials.report')
            @elseif ($tab === 'transactions')
                @include('finance.partials.transactions')
            @else
                @include('finance.partials.import')
            @endif
        </div>
    </div>
</div>
