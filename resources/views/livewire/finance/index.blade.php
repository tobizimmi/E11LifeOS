<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\BankAccount;
use App\Models\FinanceBudget;
use App\Models\ExternalLink;
use App\Models\FinanceRule;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Support\AiException;
use App\Support\Finance\BalanceForecast;
use App\Support\Finance\BankCsvImporter;
use App\Support\Finance\Budgets;
use App\Support\Finance\Categorizer;
use App\Support\Finance\FinanceAi;
use App\Support\Finance\FinanceCategories;
use App\Support\Finance\FinancePeriod;
use App\Support\Finance\FinanceReceiptAi;
use App\Support\Finance\FinanceReport;
use App\Support\Finance\TransactionReview;
use App\Support\Finance\TaxCategories;
use App\Support\Finance\TaxYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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

    /** Free from/to range, used when $period is "custom" (YYYY-MM-DD). */
    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    /** Compare the report to the equally long period right before it. */
    #[Url(except: false)]
    public bool $compare = false;

    /** Count transfers between own accounts / savings as normal income & expense (raw view). */
    #[Url(except: false)]
    public bool $includeTransfers = false;

    #[Url(except: false)]
    public bool $includeSavings = false;

    #[Url(as: 'account', except: '')]
    public string $accountFilter = '';

    /** Days the balance forecast looks ahead. */
    public int $forecastDays = 60;

    /**
     * New custom category form (Budgets tab).
     *
     * @var array{label: string, icon: string, group: string}
     */
    public array $newCategory = ['label' => '', 'icon' => '🏷️', 'group' => 'wants'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'category', except: '')]
    public string $categoryFilter = '';

    /** "with" or "without" a linked document (invoice, payslip …); "" = all. */
    #[Url(as: 'docs', except: '')]
    public string $documentFilter = '';

    #[Url(as: 'taxyear', except: 0)]
    public int $taxYear = 0;

    public string $taxSearch = '';

    public int $limit = self::TRANSACTIONS_PER_PAGE;

    public $statement = null;

    public string $importAccount = 'new';

    public string $newAccountName = '';

    /**
     * @var array{imported: int, skipped: int, errors: int, accounts?: array<int, string>}|null
     */
    public ?array $importResult = null;

    public ?string $importError = null;

    /**
     * What the uploaded file contains (format and account names), shown before importing.
     *
     * @var array{format: string, accounts: array<int, string>}|null
     */
    public ?array $detected = null;

    /**
     * The AI analyst's assessment of the current report (not stored).
     *
     * @var array<string, mixed>|null
     */
    public ?array $advice = null;

    public ?string $aiError = null;

    /**
     * Manual booking (cash purchase or receipt photo read by the AI).
     */
    public bool $manualOpen = false;

    /**
     * @var array{booked_on: string, counterparty: string, purpose: string, amount: string, category: string, account: string}
     */
    public array $manual = [];

    public $receiptPhoto = null;

    public ?string $receiptMessage = null;

    /**
     * An existing bank booking that is probably the same purchase (asked before saving a duplicate).
     */
    public ?int $matchingTransactionId = null;

    public bool $manualSaved = false;

    /**
     * Booking whose documents & mail links are shown.
     */
    public ?int $documentsFor = null;

    /**
     * Booking currently being edited inline, and its edit form.
     */
    public ?int $editingTxId = null;

    /**
     * @var array{booked_on: string, counterparty: string, purpose: string, amount: string}
     */
    public array $txEdit = ['booked_on' => '', 'counterparty' => '', 'purpose' => '', 'amount' => ''];

    public function mount(): void
    {
        if (! array_key_exists($this->period, FinancePeriod::options())) {
            $this->period = FinancePeriod::DEFAULT;
        }

        $this->taxYear = $this->taxYear >= 2000 && $this->taxYear <= 2100 ? $this->taxYear : (int) now()->year - (now()->month <= 5 ? 1 : 0);
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

    public function updatedCategoryFilter(): void
    {
        $this->advice = null;
    }

    /**
     * The bookings behind the report: period + account + (on the report) the category drill-down.
     * Eager-loads the account so the report can break expenses down per account.
     *
     * @return Builder<Transaction>
     */
    private function reportQuery(Carbon $from, Carbon $to): Builder
    {
        return FinancePeriod::queryFor($this->currentUser(), (int) $this->householdId, $from, $to, $this->accountId())
            ->with('account')
            ->when($this->categoryFilter !== '' && FinanceCategories::exists($this->categoryFilter), fn (Builder $query) => $query->where('category', $this->categoryFilter));
    }

    // ── Import ───────────────────────────────────────────────────────────

    /**
     * Looks into the uploaded file: Outbank exports with several accounts are split automatically.
     */
    public function updatedStatement(BankCsvImporter $importer): void
    {
        $this->reset('importResult', 'importError', 'detected');

        if (! $this->statement || $this->getErrorBag()->has('statement')) {
            return;
        }

        try {
            $this->detected = $importer->inspect((string) file_get_contents($this->statement->getRealPath()));
        } catch (RuntimeException $exception) {
            $this->importError = $exception->getMessage();

            return;
        }

        if ($this->detected['accounts'] !== []) {
            $this->importAccount = 'auto';
        }
    }

    public function importStatement(BankCsvImporter $importer): void
    {
        $this->reset('importResult', 'importError');
        $this->validate([
            'statement' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'importAccount' => ['required', 'string'],
            'newAccountName' => [Rule::requiredIf($this->importAccount === 'new'), 'nullable', 'string', 'max:100'],
        ], attributes: ['newAccountName' => __('Account name')]);

        if ($this->importAccount === 'auto') {
            try {
                $this->importResult = $importer->importByAccountColumn($this->currentUser()->id, (int) $this->householdId, (string) file_get_contents($this->statement->getRealPath()), new Categorizer($this->currentUser()->id));
            } catch (RuntimeException $exception) {
                $this->importError = $exception->getMessage();

                return;
            }

            $this->importAccount = (string) ($this->accounts()->where('name', $this->importResult['accounts'][0] ?? '')->value('id') ?? 'new');
            $this->reset('statement', 'newAccountName', 'advice', 'detected');

            return;
        }

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
        $this->reset('statement', 'newAccountName', 'advice', 'detected');
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

    /**
     * Opens inline editing for a booking (date, counterparty, purpose, amount).
     */
    public function editTransaction(int $id): void
    {
        $transaction = $this->findTransaction($id);
        $this->editingTxId = $transaction->id;
        $this->txEdit = [
            'booked_on' => $transaction->booked_on->toDateString(),
            'counterparty' => (string) $transaction->counterparty,
            'purpose' => (string) $transaction->purpose,
            'amount' => number_format((float) $transaction->amount, 2, ',', ''),
        ];
        $this->resetValidation();
    }

    public function cancelEditTransaction(): void
    {
        $this->reset('editingTxId', 'txEdit');
    }

    public function updateTransaction(): void
    {
        if ($this->editingTxId === null) {
            return;
        }

        $data = $this->validate([
            'txEdit.booked_on' => ['required', 'date'],
            'txEdit.counterparty' => ['nullable', 'string', 'max:255'],
            'txEdit.purpose' => ['nullable', 'string', 'max:2000'],
            'txEdit.amount' => ['required', 'regex:/^-?\d{1,9}([.,]\d{1,2})?$/'],
        ], attributes: ['txEdit.amount' => __('Amount'), 'txEdit.booked_on' => __('Date')])['txEdit'];

        $this->findTransaction($this->editingTxId)->update([
            'booked_on' => $data['booked_on'],
            'counterparty' => trim($data['counterparty']) !== '' ? mb_substr(trim($data['counterparty']), 0, 255) : null,
            'purpose' => trim($data['purpose']) !== '' ? mb_substr(trim($data['purpose']), 0, 2000) : null,
            'amount' => round((float) str_replace(',', '.', $data['amount']), 2),
        ]);

        $this->reset('editingTxId', 'txEdit');
        $this->advice = null;
        $this->dispatch('toast', message: __('Booking updated.'));
    }

    public function deleteTransaction(int $id): void
    {
        $transaction = $this->findTransaction($id);
        ExternalLink::where('linkable_type', 'transaction')->where('linkable_id', $transaction->id)->get()->each->delete();
        $transaction->delete();

        $this->reset('editingTxId', 'txEdit');
        $this->advice = null;
        $this->dispatch('toast', message: __('Booking deleted.'));
    }

    // ── Review: internal transfers & duplicates (detection + confirm) ─
    public function confirmTransfer(int $outId, int $inId): void
    {
        $this->findTransaction($outId)->update(['category' => FinanceCategories::TRANSFER, 'category_locked' => true]);
        $this->findTransaction($inId)->update(['category' => FinanceCategories::TRANSFER, 'category_locked' => true]);
        $this->dispatch('toast', message: __('Marked as an internal transfer – no longer counted as income or expense.'));
    }

    public function ignoreTransfer(int $outId, int $inId): void
    {
        $this->findTransaction($outId)->update(['review_ignored_at' => now()]);
        $this->findTransaction($inId)->update(['review_ignored_at' => now()]);
    }

    /**
     * Marks every detected transfer pair at once as an internal transfer, so they drop out of the
     * income/expense totals in one step instead of confirming each pair by hand.
     */
    public function confirmAllTransfers(): void
    {
        $pairs = app(TransactionReview::class)->transfers($this->currentUser(), (int) $this->householdId);

        foreach ($pairs as $pair) {
            $this->findTransaction($pair['out']->id)->update(['category' => FinanceCategories::TRANSFER, 'category_locked' => true]);
            $this->findTransaction($pair['in']->id)->update(['category' => FinanceCategories::TRANSFER, 'category_locked' => true]);
        }

        $this->advice = null;
        $this->dispatch('toast', message: trans_choice(':count transfer confirmed.|:count transfers confirmed.', count($pairs)));
    }

    public function confirmDuplicate(int $keepId, int $duplicateId): void
    {
        $keep = $this->findTransaction($keepId);
        $duplicate = $this->findTransaction($duplicateId);

        if ($keep->id !== $duplicate->id) {
            $duplicate->update(['duplicate_of_id' => $keep->id]);
            $this->dispatch('toast', message: __('Duplicate removed from the totals.'));
        }
    }

    public function ignoreDuplicate(int $keepId, int $duplicateId): void
    {
        $this->findTransaction($keepId)->update(['review_ignored_at' => now()]);
        $this->findTransaction($duplicateId)->update(['review_ignored_at' => now()]);
    }

    public function deleteRule(int $ruleId): void
    {
        FinanceRule::where('user_id', $this->currentUser()->id)->whereKey($ruleId)->delete();
    }

    /**
     * Applies all remembered rules and the keyword catalogue to the not-yet-locked bookings – useful
     * after an import done before a rule existed, or after editing the rules.
     */
    public function applyRules(): void
    {
        $categorizer = new Categorizer($this->currentUser()->id);
        $changed = 0;

        Transaction::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)
            ->where('category_locked', false)->get()
            ->each(function (Transaction $transaction) use ($categorizer, &$changed) {
                $category = $categorizer->categorize($transaction->counterparty, $transaction->purpose, (float) $transaction->amount);

                if ($category !== $transaction->category) {
                    $transaction->update(['category' => $category]);
                    $changed++;
                }
            });

        $this->dispatch('toast', message: trans_choice('{0}No bookings changed.|{1}:count booking recategorised.|[2,*]:count bookings recategorised.', $changed, ['count' => $changed]));
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
        $account = $this->accounts()->find($accountId) ?? abort(404);
        \App\Models\ExternalLink::where('linkable_type', 'transaction')->whereIn('linkable_id', $account->transactions()->select('id'))->get()->each->delete();
        $account->delete();
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

    /**
     * Sets (or with an empty value clears) an account's balance anchor as of today, so the current
     * balance and the net total can be shown. Bank-synced accounts keep getting it from the sync.
     */
    public function setBalance(int $accountId, string $value): void
    {
        $account = $this->accounts()->find($accountId) ?? abort(404);
        $parsed = trim($value) === '' ? null : BankCsvImporter::parseAmount($value);

        $account->update([
            'balance' => $parsed,
            'balance_on' => $parsed === null ? null : today()->toDateString(),
        ]);
        $this->advice = null;
    }

    // ── Report ───────────────────────────────────────────────────────────

    // ── Tax ──────────────────────────────────────────────────────────────

    public function setTaxCategory(int $transactionId, string $category): void
    {
        abort_unless($category === '' || array_key_exists($category, TaxCategories::all()), 422);
        $transaction = $this->findTransaction($transactionId);
        $transaction->update(['tax_category' => $category ?: null, 'tax_amount' => $category === '' ? null : $transaction->tax_amount]);
    }

    /**
     * The tax-relevant part of a booking, e.g. the labour costs of a craftsman invoice (empty = whole amount).
     */
    public function setTaxAmount(int $transactionId, string $amount): void
    {
        $amount = trim(str_replace(['€', ' '], '', $amount));
        $amount = str_contains($amount, ',') ? str_replace(['.', ','], ['', '.'], $amount) : $amount;
        $transaction = $this->findTransaction($transactionId);

        if ($amount !== '' && (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > abs($transaction->amount))) {
            $this->addError('taxAmount.'.$transactionId, __('At most the amount of the booking.'));

            return;
        }

        $transaction->update(['tax_amount' => $amount === '' ? null : round((float) $amount, 2)]);
    }

    public function acceptTaxSuggestions(): void
    {
        $accepted = 0;

        foreach ($this->taxSuggestions() as $row) {
            $row['transaction']->update(['tax_category' => $row['category']]);
            $accepted++;
        }

        $this->dispatch('toast', message: trans_choice(':count booking assigned.|:count bookings assigned.', $accepted));
    }

    public function saveTaxSettings(string $rate, string $children): void
    {
        $data = validator(['rate' => $rate, 'children' => $children], [
            'rate' => ['required', 'numeric', 'min:0', 'max:45'],
            'children' => ['required', 'integer', 'min:0', 'max:20'],
        ])->validate();

        $this->currentUser()->update(['tax_rate' => (float) $data['rate'], 'tax_children' => (int) $data['children']]);
    }

    /**
     * Untagged expenses of the tax year that look tax-relevant.
     *
     * @return list<array{transaction: Transaction, category: string}>
     */
    private function taxSuggestions(): array
    {
        return Transaction::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)
            ->whereNull('tax_category')->where('amount', '<', 0)
            ->whereBetween('booked_on', [$this->taxYear.'-01-01', $this->taxYear.'-12-31'])
            ->orderBy('booked_on')->get()
            ->map(fn (Transaction $transaction) => ['transaction' => $transaction, 'category' => TaxCategories::suggest($transaction)])
            ->filter(fn (array $row) => $row['category'] !== null)
            ->values()->all();
    }

    // ── Manual bookings / receipts ───────────────────────────────────────

    public function openManual(): void
    {
        $this->resetValidation();
        $this->reset('receiptPhoto', 'receiptMessage', 'matchingTransactionId', 'manualSaved');
        $this->manual = ['booked_on' => today()->toDateString(), 'counterparty' => '', 'purpose' => '', 'amount' => '', 'category' => 'groceries', 'account' => 'cash'];
        $this->manualOpen = true;
        $this->tab = 'transactions';
    }

    public function closeManual(): void
    {
        $this->reset('manualOpen', 'manual', 'receiptPhoto', 'receiptMessage', 'matchingTransactionId');
    }

    public function updatedManual(): void
    {
        $this->matchingTransactionId = null;
    }

    public function readReceiptPhoto(FinanceReceiptAi $ai): void
    {
        $this->receiptMessage = null;
        $this->validate(['receiptPhoto' => ['nullable', 'file', 'max:10240']]);

        if (! $this->receiptPhoto || ! in_array($this->receiptPhoto->getMimeType(), FinanceReceiptAi::MEDIA_TYPES, true)) {
            $this->receiptMessage = __('Add a photo or PDF of the receipt first.');

            return;
        }

        try {
            $result = $ai->read((string) file_get_contents($this->receiptPhoto->getRealPath()), $this->receiptPhoto->getMimeType());
        } catch (AiException $exception) {
            $this->receiptMessage = $exception->getMessage();

            return;
        }

        foreach (['booked_on', 'counterparty', 'purpose', 'category'] as $field) {
            if (filled($result[$field])) {
                $this->manual[$field] = $result[$field];
            }
        }

        if ($result['amount'] !== null) {
            $this->manual['amount'] = number_format($result['amount'], 2, ',', '');
        }

        $this->receiptMessage = $result['amount'] !== null ? __('Filled in from the receipt – please check.') : __('Nothing could be read from the receipt.');
    }

    /**
     * Saves the expense. If a bank booking with the same amount a few days around the date
     * exists, it asks first: $mode "assign" only sets the category of that booking, "new"
     * books it anyway.
     */
    public function saveManual(string $mode = ''): void
    {
        $this->manual['amount'] = str_replace(['€', ' '], '', (string) ($this->manual['amount'] ?? ''));
        $validated = $this->validate([
            'manual.booked_on' => ['required', 'date'],
            'manual.counterparty' => ['required', 'string', 'max:255'],
            'manual.purpose' => ['nullable', 'string', 'max:255'],
            'manual.amount' => ['required', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'manual.category' => ['required', Rule::in(array_keys(FinanceCategories::all()))],
            'manual.account' => ['required', 'string'],
        ], attributes: ['manual.counterparty' => __('Shop / payee'), 'manual.amount' => __('Amount'), 'manual.booked_on' => __('Date')])['manual'];

        $amount = round((float) str_replace(',', '.', $validated['amount']), 2);
        $date = \Illuminate\Support\Carbon::parse($validated['booked_on']);
        $user = $this->currentUser();

        $match = Transaction::where('user_id', $user->id)->where('household_id', $this->householdId)
            ->where('amount', -$amount)
            ->whereBetween('booked_on', [$date->copy()->subDays(4)->toDateString(), $date->copy()->addDays(4)->toDateString()])
            ->orderBy('booked_on')->first();

        if ($match && $mode === '') {
            $this->matchingTransactionId = $match->id;

            return;
        }

        if ($match && $mode === 'assign') {
            $match->update([
                'category' => $validated['category'],
                'category_locked' => true,
                'purpose' => trim($match->purpose.' · '.($validated['purpose'] ?: $validated['counterparty']), ' ·'),
            ]);
        } else {
            $account = ctype_digit($validated['account'])
                ? ($this->accounts()->find((int) $validated['account']) ?? abort(404))
                : BankAccount::firstOrCreate(['user_id' => $user->id, 'household_id' => $this->householdId, 'name' => __('Cash wallet')]);

            Transaction::create([
                'household_id' => $this->householdId,
                'user_id' => $user->id,
                'bank_account_id' => $account->id,
                'booked_on' => $date->toDateString(),
                'amount' => -$amount,
                'counterparty' => trim($validated['counterparty']),
                'purpose' => trim((string) $validated['purpose']),
                'category' => $validated['category'],
                'category_locked' => true,
                'import_hash' => sha1('manual|'.\Illuminate\Support\Str::uuid()),
            ]);
        }

        $this->closeManual();
        $this->manualSaved = true;
    }

    /**
     * Sets (or with an empty value removes) the monthly budget of a spending category.
     */
    public function saveBudget(string $category, string $limit): void
    {
        abort_unless(array_key_exists($category, FinanceCategories::all()) && ! in_array(FinanceCategories::group($category), ['income', 'transfer'], true), 404);
        $value = trim($limit) === '' ? null : BankCsvImporter::parseAmount($limit);

        if ($value === null || $value <= 0) {
            FinanceBudget::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)->where('category', $category)->delete();

            return;
        }

        FinanceBudget::updateOrCreate(
            ['user_id' => $this->currentUser()->id, 'household_id' => $this->householdId, 'category' => $category],
            ['monthly_limit' => min(round(abs($value), 2), 9999999)],
        );
    }

    /**
     * Turns a budget into a pot (left-over money carries into the next month) or back.
     */
    public function toggleRollover(string $category): void
    {
        $budget = FinanceBudget::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)->where('category', $category)->firstOrFail();
        $budget->update(['rollover_from' => $budget->rollover_from ? null : now()->startOfMonth()]);
    }

    /**
     * Creates a household category of your own (name, icon, 50/30/20 group).
     */
    public function addCategory(): void
    {
        $data = $this->validate([
            'newCategory.label' => ['required', 'string', 'max:40'],
            'newCategory.icon' => ['nullable', 'string', 'max:16'],
            'newCategory.group' => ['required', Rule::in(['needs', 'wants', 'savings'])],
        ], attributes: ['newCategory.label' => __('Name')])['newCategory'];

        \App\Models\FinanceCategory::create([
            'household_id' => $this->householdId,
            'user_id' => $this->currentUser()->id,
            'key' => 'cat_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
            'label' => trim($data['label']),
            'icon' => trim($data['icon']) ?: '🏷️',
            'group' => $data['group'],
        ]);

        FinanceCategories::flush();
        $this->newCategory = ['label' => '', 'icon' => '🏷️', 'group' => 'wants'];
        $this->dispatch('toast', message: __('Category added.'));
    }

    /**
     * Removes a custom category; its bookings and budget fall back to "Other expenses".
     */
    public function deleteCategory(string $key): void
    {
        abort_unless(FinanceCategories::isCustom($key), 404);

        \App\Models\FinanceCategory::where('household_id', $this->householdId)->where('key', $key)->delete();
        Transaction::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)->where('category', $key)
            ->update(['category' => FinanceCategories::OTHER]);
        FinanceBudget::where('household_id', $this->householdId)->where('category', $key)->delete();
        FinanceRule::where('user_id', $this->currentUser()->id)->where('category', $key)->update(['category' => FinanceCategories::OTHER]);

        FinanceCategories::flush();
        $this->advice = null;
        $this->dispatch('toast', message: __('Category removed – its bookings are now “Other expenses”.'));
    }

    public function askAnalyst(FinanceAi $ai): void
    {
        $this->aiError = null;
        [$from, $to] = FinancePeriod::range($this->period, $this->customFrom, $this->customTo);
        $report = FinanceReport::build($this->reportQuery($from, $to)->get(), $from, $to, ['includeTransfers' => $this->includeTransfers, 'includeSavings' => $this->includeSavings]);

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
            'receiptAiAvailable' => FinanceReceiptAi::isConfigured(),
        ];

        if ($this->tab === 'transactions') {
            $query = FinancePeriod::query($this->currentUser(), (int) $this->householdId, $this->period, $this->accountId())
                ->when($this->categoryFilter !== '', fn ($query) => $query->where('category', $this->categoryFilter))
                ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereRaw('LOWER(counterparty) LIKE ?', ['%'.mb_strtolower(trim($this->search)).'%'])
                    ->orWhereRaw('LOWER(purpose) LIKE ?', ['%'.mb_strtolower(trim($this->search)).'%'])))
                ->when(in_array($this->documentFilter, ['with', 'without'], true), function ($query) {
                    $documents = ExternalLink::query()->select('linkable_id')->where('linkable_type', 'transaction')->where('household_id', $this->householdId);

                    $this->documentFilter === 'with' ? $query->whereIn('id', $documents) : $query->whereNotIn('id', $documents);
                });

            $data['transactionCount'] = (clone $query)->count();
            $data['matchingTransaction'] = $this->matchingTransactionId ? $this->findTransaction($this->matchingTransactionId) : null;
            $data['transactions'] = $query->with('account')->orderByDesc('booked_on')->orderByDesc('id')->limit($this->limit)->get();
            $data['documentCounts'] = ExternalLink::where('linkable_type', 'transaction')->where('household_id', $this->householdId)
                ->whereIn('linkable_id', $data['transactions']->pluck('id'))->selectRaw('linkable_id, COUNT(*) as total')->groupBy('linkable_id')->pluck('total', 'linkable_id');
            $data['rules'] = FinanceRule::where('user_id', $this->currentUser()->id)->orderBy('pattern')->get();
        }

        if ($this->tab === 'budgets') {
            $status = collect(Budgets::status($this->currentUser(), (int) $this->householdId))->keyBy(fn (array $row) => $row['budget']->category);
            $spentThisMonth = Transaction::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)
                ->whereBetween('booked_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->where('amount', '<', 0)->selectRaw('category, SUM(amount) as total')->groupBy('category')->pluck('total', 'category');
            $data['budgetStatus'] = $status;
            $data['spentThisMonth'] = $spentThisMonth;
            $data['budgetCategories'] = collect(FinanceCategories::all())->reject(fn (array $category) => in_array($category['group'], ['income', 'transfer'], true));
            $data['customCategories'] = \App\Models\FinanceCategory::where('household_id', $this->householdId)->orderBy('label')->get();
        }

        if ($this->tab === 'tax') {
            $data['taxYearData'] = TaxYear::build($this->currentUser(), (int) $this->householdId, $this->taxYear);
            $data['taxSuggestions'] = $this->taxSuggestions();
            $data['taxCategories'] = TaxCategories::all();
            $data['taxSearchResults'] = trim($this->taxSearch) === '' ? collect() : Transaction::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)
                ->whereNull('tax_category')->where('amount', '<', 0)
                ->whereBetween('booked_on', [$this->taxYear.'-01-01', $this->taxYear.'-12-31'])
                ->where(fn ($query) => $query->whereRaw('LOWER(counterparty) LIKE ?', ['%'.mb_strtolower(trim($this->taxSearch)).'%'])->orWhereRaw('LOWER(purpose) LIKE ?', ['%'.mb_strtolower(trim($this->taxSearch)).'%']))
                ->orderByDesc('booked_on')->limit(20)->get();
            $firstBooking = Transaction::where('user_id', $this->currentUser()->id)->min('booked_on');
            $data['taxYears'] = collect(range((int) now()->year, min((int) now()->year - 1, $firstBooking ? (int) substr((string) $firstBooking, 0, 4) : (int) now()->year - 1)))
                ->push($this->taxYear)->unique()->sortDesc()->values()->all();
        }

        if ($this->tab === 'forecast') {
            $this->forecastDays = in_array($this->forecastDays, BalanceForecast::HORIZONS, true) ? $this->forecastDays : 60;
            $data['forecast'] = BalanceForecast::for($accounts, $this->forecastDays);
        }

        if ($this->tab === 'accounts') {
            $accountRows = $accounts->map(fn (BankAccount $account) => [
                'account' => $account,
                'balance' => $account->currentBalance(),
                'bookings' => (int) ($account->transactions_count ?? 0),
            ])->values();
            $data['accountRows'] = $accountRows;
            $data['accountsTotal'] = round((float) $accountRows->sum(fn (array $row) => (float) ($row['balance'] ?? 0)), 2);
            $data['accountsMissing'] = $accountRows->filter(fn (array $row) => $row['balance'] === null)->count();

            $portfolios = Portfolio::where('user_id', $this->currentUser()->id)
                ->with(['snapshots' => fn ($query) => $query->latest('taken_at')->limit(1)])
                ->orderBy('name')->get();
            $data['depotRows'] = $portfolios->map(fn (Portfolio $portfolio) => [
                'portfolio' => $portfolio,
                'value' => $portfolio->snapshots->first()?->total_value,
                'date' => $portfolio->snapshots->first()?->taken_at,
            ])->values();
            $data['depotsTotal'] = round((float) $portfolios->sum(fn (Portfolio $portfolio) => (float) ($portfolio->snapshots->first()?->total_value ?? 0)), 2);
        }

        if ($this->tab === 'report') {
            [$from, $to] = FinancePeriod::range($this->period, $this->customFrom, $this->customTo);
            $reportOptions = ['includeTransfers' => $this->includeTransfers, 'includeSavings' => $this->includeSavings];
            $report = FinanceReport::build($this->reportQuery($from, $to)->get(), $from, $to, $reportOptions);
            $data['report'] = $report;
            $data['openCount'] = Transaction::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)->whereIn('category', [FinanceCategories::OTHER, FinanceCategories::OTHER_INCOME])->where('category_locked', false)->count();

            $data['comparison'] = null;
            $data['deltas'] = [];

            if ($this->compare) {
                [$prevFrom, $prevTo] = FinancePeriod::previousRange($from, $to);
                $previous = FinanceReport::build($this->reportQuery($prevFrom, $prevTo)->get(), $prevFrom, $prevTo, $reportOptions);
                $data['comparison'] = ['from' => $prevFrom, 'to' => $prevTo];
                $data['deltas'] = [
                    ['label' => __('Income'), 'delta' => round((float) $report['income'] - (float) $previous['income'], 2), 'higherBetter' => true],
                    ['label' => __('Expenses'), 'delta' => round((float) $report['expenses'] - (float) $previous['expenses'], 2), 'higherBetter' => false],
                    ['label' => __('Left over'), 'delta' => round((float) $report['balance'] - (float) $previous['balance'], 2), 'higherBetter' => true],
                ];
            }

            $review = app(TransactionReview::class);
            $data['reviewTransfers'] = $review->transfers($this->currentUser(), (int) $this->householdId);
            $data['reviewDuplicates'] = $review->duplicates($this->currentUser(), (int) $this->householdId);
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
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Finances') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your bank data.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            {{-- Tabs + filters --}}
            <div class="flex flex-wrap items-center gap-3">
                <x-section-nav class="w-full md:w-auto" :label="__('Finances')" :active="$tab" :groups="[
                    __('Overview') => ['report' => ['📊', __('Report')], 'accounts' => ['🏦', __('Accounts')], 'forecast' => ['🔮', __('Forecast')], 'budgets' => ['🎯', __('Budgets')]],
                    __('Data') => ['transactions' => ['📄', __('Bookings')], 'tax' => ['🏛', __('Tax')], 'import' => ['⬆', __('Import')]],
                ]" />
                @if (! in_array($tab, ['import', 'budgets', 'tax', 'forecast', 'accounts'], true))
                    <select wire:model.live="period" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Period') }}">
                        @foreach ($periods as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($period === \App\Support\Finance\FinancePeriod::CUSTOM)
                        <input type="date" wire:model.live="customFrom" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('From') }}" />
                        <span class="text-gray-400" aria-hidden="true">–</span>
                        <input type="date" wire:model.live="customTo" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('To') }}" />
                    @endif
                    @if ($accounts->count() > 1)
                        <select wire:model.live="accountFilter" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Account') }}">
                            <option value="">{{ __('All accounts') }}</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    @if ($tab === 'report')
                        <select wire:model.live="categoryFilter" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Category') }}">
                            <option value="">{{ __('All categories') }}</option>
                            @foreach ($categories as $key => $category)
                                <option value="{{ $key }}">{{ $category['icon'] }} {{ $category['label'] }}</option>
                            @endforeach
                        </select>
                        <label class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                            <input type="checkbox" wire:model.live="compare" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Compare with previous period') }}
                        </label>
                        <label class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300" title="{{ __('Count transfers between your own accounts and savings as normal income/expense.') }}">
                            <input type="checkbox" wire:model.live="includeTransfers" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Include transfers') }}
                        </label>
                        <label class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                            <input type="checkbox" wire:model.live="includeSavings" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Count savings as expense') }}
                        </label>
                    @endif
                @endif
            </div>

            @if ($accounts->isEmpty() && ! in_array($tab, ['import', 'tax'], true) && ! $manualOpen)
                <div class="rounded-2xl bg-white p-8 text-center shadow-xs dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🏦</p>
                    <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Import your first bank statement') }}</h3>
                    <p class="mx-auto mt-1 max-w-lg text-sm text-gray-600 dark:text-gray-400">{{ __('Export the transactions of your account as CSV in online banking (Sparkasse, ING, DKB, comdirect, N26, Volksbank …) or in Outbank and import the file here. The analysis then shows where your money goes and where you can save.') }}</p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <x-primary-button type="button" wire:click="$set('tab', 'import')">⬆ {{ __('Import statement') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="openManual">🧾 {{ __('Add receipt or cash expense') }}</x-secondary-button>
                    </div>
                </div>
            @elseif ($tab === 'report')
                @include('finance.partials.report')
            @elseif ($tab === 'accounts')
                @include('finance.partials.accounts')
            @elseif ($tab === 'forecast')
                @include('finance.partials.forecast')
            @elseif ($tab === 'transactions')
                @include('finance.partials.transactions')
            @elseif ($tab === 'budgets')
                @include('finance.partials.budgets')
            @elseif ($tab === 'tax')
                @include('finance.partials.tax')
            @else
                @include('finance.partials.import')
            @endif
        </div>
    </div>
</div>
