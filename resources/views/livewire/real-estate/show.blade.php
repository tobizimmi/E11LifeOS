<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\Item;
use App\Models\RealEstate;
use App\Models\RealEstateBudgetItem;
use App\Models\RealEstateInvoice;
use App\Models\RealEstateLoan;
use App\Models\RealEstateSubsidy;
use App\Models\Transaction;
use App\Support\AiException;
use App\Support\Finance\FinanceReceiptAi;
use App\Support\RealEstate\RealEstatePlan;
use App\Support\RealEstate\RealEstateTax;
use App\Support\RealEstate\SubsidyPrograms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use WithFileUploads;

    public const TABS = ['overview', 'purchase', 'budget', 'invoices', 'subsidies', 'rental', 'value', 'tax'];

    #[Locked]
    public int $realEstateId;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    #[Url(as: 'year', except: 0)]
    public int $taxYear = 0;

    /**
     * @var array<string, mixed>
     */
    public array $details = [];

    /**
     * @var array{lender: string, kind: string, amount: string, interest_rate: string, repayment_rate: string, fixed_years: string, start_on: string}
     */
    public array $loan = [];

    public ?int $scheduleLoanId = null;

    public ?int $editingItemId = null;

    /**
     * @var array<string, mixed>
     */
    public array $item = [];

    public ?int $editingInvoiceId = null;

    /**
     * @var array<string, mixed>
     */
    public array $invoice = [];

    public $receipt = null;

    public ?string $receiptMessage = null;

    /**
     * With paperless connected, receipts go there instead of into the app.
     */
    public bool $receiptToPaperless = true;

    public ?int $editingSubsidyId = null;

    /**
     * @var array<string, mixed>
     */
    public array $subsidy = [];

    public function mount(int $realEstate): void
    {
        $this->resolveHousehold();
        $this->realEstateId = $this->findRealEstate($realEstate)->id;
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'overview';
        $this->taxYear = $this->taxYear >= 2000 && $this->taxYear <= 2100 ? $this->taxYear : (int) now()->year;
        $this->fillDetails();
        $this->resetLoan();
        $this->resetItem();
        $this->resetInvoice();
        $this->resetSubsidy();
    }

    private function findRealEstate(?int $id = null): RealEstate
    {
        return RealEstate::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($id ?? $this->realEstateId) ?? abort(404);
    }

    private function fillDetails(): void
    {
        $model = $this->findRealEstate();
        $this->details = collect($model->only([
            'name', 'street', 'postal_code', 'city', 'state', 'usage', 'rental_share', 'status', 'purchase_price', 'land_share', 'transfer_tax_rate',
            'notary_rate', 'land_registry_rate', 'broker_rate', 'living_area', 'plot_area', 'construction_year', 'equity', 'monthly_rent', 'monthly_costs', 'tax_rate', 'notes',
        ]))->map(fn ($value) => $value === null ? '' : (string) $value)->all();
        $this->details['purchase_date'] = (string) $model->purchase_date?->toDateString();
        $this->details['private'] = $model->isPrivate();
    }

    // ── Details, purchase & financing ────────────────────────────────────

    public function saveDetails(): void
    {
        $number = fn (int $max) => ['nullable', 'numeric', 'min:0', 'max:'.$max];
        $data = $this->validate([
            'details.name' => ['required', 'string', 'max:255'],
            'details.street' => ['nullable', 'string', 'max:255'],
            'details.postal_code' => ['nullable', 'string', 'max:10'],
            'details.city' => ['nullable', 'string', 'max:255'],
            'details.state' => ['required', Rule::in(array_keys(RealEstate::TRANSFER_TAX))],
            'details.usage' => ['required', Rule::in(RealEstate::USAGES)],
            'details.rental_share' => ['nullable', 'integer', 'min:0', 'max:100'],
            'details.status' => ['required', Rule::in(RealEstate::STATUSES)],
            'details.purchase_price' => $number(100000000),
            'details.purchase_date' => ['nullable', 'date'],
            'details.land_share' => ['required', 'numeric', 'min:0', 'max:100'],
            'details.transfer_tax_rate' => $number(20),
            'details.notary_rate' => ['required', 'numeric', 'min:0', 'max:10'],
            'details.land_registry_rate' => ['required', 'numeric', 'min:0', 'max:10'],
            'details.broker_rate' => ['required', 'numeric', 'min:0', 'max:10'],
            'details.living_area' => $number(100000),
            'details.plot_area' => $number(10000000),
            'details.construction_year' => ['nullable', 'integer', 'min:1000', 'max:2100'],
            'details.equity' => $number(100000000),
            'details.monthly_rent' => $number(1000000),
            'details.monthly_costs' => $number(1000000),
            'details.tax_rate' => ['required', 'numeric', 'min:0', 'max:60'],
            'details.notes' => ['nullable', 'string', 'max:10000'],
            'details.private' => ['boolean'],
        ], attributes: ['details.name' => __('Name')])['details'];

        $model = $this->findRealEstate();
        $attributes = collect($data)->except('private')->map(fn ($value) => $value === '' ? null : $value)->all();
        $attributes['rental_share'] = (int) ($attributes['rental_share'] ?? 0);

        if ($model->created_by === Auth::id()) {
            $attributes['visibility'] = $data['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
        }

        $model->update($attributes);
        $this->fillDetails();
        $this->dispatch('toast', message: __('Saved.'));
    }

    private function resetLoan(): void
    {
        $this->loan = ['lender' => '', 'kind' => 'annuity', 'amount' => '', 'interest_rate' => '3.5', 'repayment_rate' => '2', 'fixed_years' => '10', 'start_on' => ''];
    }

    public function addLoan(): void
    {
        $data = $this->validate([
            'loan.lender' => ['required', 'string', 'max:255'],
            'loan.kind' => ['required', Rule::in(RealEstateLoan::KINDS)],
            'loan.amount' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'loan.interest_rate' => ['required', 'numeric', 'min:0', 'max:20'],
            'loan.repayment_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'loan.fixed_years' => ['required', 'integer', 'min:1', 'max:40'],
            'loan.start_on' => ['nullable', 'date'],
        ], attributes: ['loan.lender' => __('Lender'), 'loan.amount' => __('Loan amount (€)')])['loan'];

        $this->findRealEstate()->loans()->create(['start_on' => $data['start_on'] ?: null] + $data);
        $this->resetLoan();
    }

    public function deleteLoan(int $id): void
    {
        $this->findRealEstate()->loans()->whereKey($id)->delete();
        $this->scheduleLoanId = null;
    }

    // ── Budget & trades ──────────────────────────────────────────────────

    private function resetItem(): void
    {
        $this->editingItemId = null;
        $this->item = ['phase' => 'renovation', 'trade' => 'other', 'title' => '', 'planned_amount' => '', 'status' => 'planned', 'start_on' => '', 'end_on' => '', 'contractor' => '', 'energy_measure' => false, 'notes' => ''];
    }

    public function editItem(int $id): void
    {
        $item = $this->findRealEstate()->budgetItems()->findOrFail($id);
        $this->editingItemId = $item->id;
        $this->item = [
            ...collect($item->only(['phase', 'trade', 'title', 'status', 'contractor', 'notes']))->map(fn ($value) => (string) $value)->all(),
            'planned_amount' => (string) $item->planned_amount,
            'start_on' => (string) $item->start_on?->toDateString(),
            'end_on' => (string) $item->end_on?->toDateString(),
            'energy_measure' => $item->energy_measure,
        ];
    }

    public function cancelItem(): void
    {
        $this->resetItem();
    }

    /**
     * Picking an energy-related trade ticks "energy measure" (can be unticked).
     */
    public function updatedItemTrade(string $trade): void
    {
        if (! $this->editingItemId) {
            $this->item['energy_measure'] = in_array($trade, RealEstateBudgetItem::ENERGY_TRADES, true);
            $this->item['title'] = $this->item['title'] !== '' ? $this->item['title'] : (RealEstateBudgetItem::tradeLabels()[$trade] ?? '');
        }
    }

    public function saveItem(): void
    {
        $data = $this->validate([
            'item.phase' => ['required', Rule::in(RealEstateBudgetItem::PHASES)],
            'item.trade' => ['required', Rule::in(RealEstateBudgetItem::TRADES)],
            'item.title' => ['required', 'string', 'max:255'],
            'item.planned_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'item.status' => ['required', Rule::in(RealEstateBudgetItem::STATUSES)],
            'item.start_on' => ['nullable', 'date'],
            'item.end_on' => ['nullable', 'date', 'after_or_equal:item.start_on'],
            'item.contractor' => ['nullable', 'string', 'max:255'],
            'item.energy_measure' => ['boolean'],
            'item.notes' => ['nullable', 'string', 'max:5000'],
        ], attributes: ['item.title' => __('Title'), 'item.end_on' => __('End')])['item'];

        $attributes = collect($data)->map(fn ($value) => $value === '' ? null : $value)->all();
        $attributes['planned_amount'] = (float) ($attributes['planned_amount'] ?? 0);
        $model = $this->findRealEstate();

        if ($this->editingItemId) {
            $model->budgetItems()->findOrFail($this->editingItemId)->update($attributes);
        } else {
            $model->budgetItems()->create($attributes + ['position' => (int) $model->budgetItems()->max('position') + 1]);
        }

        $this->resetItem();
    }

    public function setItemStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, RealEstateBudgetItem::STATUSES, true), 422);
        $this->findRealEstate()->budgetItems()->findOrFail($id)->update(['status' => $status]);
    }

    /**
     * Moves a trade in the schedule by some days, or with $endOnly only changes its end.
     */
    public function shiftItem(int $id, int $days, bool $endOnly = false): void
    {
        $item = $this->findRealEstate()->budgetItems()->findOrFail($id);
        $days = max(-3650, min(3650, $days));

        if (! $item->start_on || $days === 0) {
            return;
        }

        if ($endOnly) {
            $end = ($item->end_on ?? $item->start_on)->copy()->addDays($days);
            $item->update(['end_on' => $end->lt($item->start_on) ? $item->start_on : $end]);

            return;
        }

        $item->update([
            'start_on' => $item->start_on->copy()->addDays($days),
            'end_on' => $item->end_on?->copy()->addDays($days),
        ]);
    }

    public function deleteItem(int $id): void
    {
        $this->findRealEstate()->budgetItems()->findOrFail($id)->delete();
        $this->resetItem();
    }

    /**
     * Adds typical purchase and renovation lines as a starting point.
     */
    public function addTemplateItems(): void
    {
        $model = $this->findRealEstate();
        $position = (int) $model->budgetItems()->max('position');

        foreach (['energy_advice', 'demolition', 'electrical', 'plumbing', 'heating', 'windows', 'insulation', 'bathroom', 'floors', 'painting', 'kitchen'] as $trade) {
            if (! $model->budgetItems()->where('trade', $trade)->exists()) {
                $model->budgetItems()->create([
                    'phase' => 'renovation', 'trade' => $trade, 'title' => RealEstateBudgetItem::tradeLabels()[$trade],
                    'energy_measure' => in_array($trade, RealEstateBudgetItem::ENERGY_TRADES, true), 'position' => ++$position,
                ]);
            }
        }
    }

    /**
     * Puts a trade (or a subsidy deadline) on the household's task list.
     */
    public function createTask(string $type, int $id): void
    {
        $model = $this->findRealEstate();
        [$title, $due] = match ($type) {
            'item' => (function () use ($model, $id) {
                $item = $model->budgetItems()->findOrFail($id);

                return [$model->name.': '.$item->title, $item->start_on?->toDateString()];
            })(),
            'subsidy' => (function () use ($model, $id) {
                $subsidy = $model->subsidies()->findOrFail($id);

                return [$model->name.': '.__('Apply for :program', ['program' => $subsidy->title]), $subsidy->deadline_on?->toDateString()];
            })(),
            default => abort(404),
        };

        $tasks = Collection::tasksFor((int) $this->householdId)->load('properties');
        $task = new Item([
            'household_id' => $this->householdId,
            'collection_id' => $tasks->id,
            'type' => Item::TYPE_TODO,
            'visibility' => $model->isPrivate() ? Item::VISIBILITY_PRIVATE : $tasks->entryVisibility(),
            'title' => mb_substr($title, 0, 255),
            'content' => ['body' => route('real-estate.show', $model)],
            'created_by' => Auth::id(),
            'position' => (int) Item::where('collection_id', $tasks->id)->max('position') + 1,
        ]);

        if ($due && ($dueProperty = $tasks->systemProperty('due'))) {
            $task->setPropertyValue($dueProperty, $due);
        }

        $task->save();
        $this->dispatch('toast', message: __('Task created.'));
    }

    // ── Invoices ─────────────────────────────────────────────────────────

    private function resetInvoice(): void
    {
        $this->editingInvoiceId = null;
        $this->receipt = null;
        $this->receiptMessage = null;
        $this->invoice = ['budget_item_id' => '', 'vendor' => '', 'number' => '', 'invoice_date' => today()->toDateString(), 'due_on' => '', 'amount' => '', 'labor_amount' => '', 'paid_on' => '', 'tax_treatment' => 'auto', 'notes' => ''];
    }

    public function editInvoice(int $id): void
    {
        $invoice = $this->findRealEstate()->invoices()->findOrFail($id);
        $this->resetInvoice();
        $this->editingInvoiceId = $invoice->id;
        $this->invoice = [
            'budget_item_id' => (string) $invoice->budget_item_id,
            'vendor' => $invoice->vendor,
            'number' => (string) $invoice->number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'due_on' => (string) $invoice->due_on?->toDateString(),
            'amount' => (string) $invoice->amount,
            'labor_amount' => $invoice->labor_amount > 0 ? (string) $invoice->labor_amount : '',
            'paid_on' => (string) $invoice->paid_on?->toDateString(),
            'tax_treatment' => $invoice->tax_treatment,
            'notes' => (string) $invoice->notes,
        ];
    }

    public function cancelInvoice(): void
    {
        $this->resetInvoice();
    }

    public function readInvoiceReceipt(FinanceReceiptAi $ai): void
    {
        $this->receiptMessage = null;
        $this->validate(['receipt' => ['nullable', 'file', 'max:20480']]);

        if (! $this->receipt || ! in_array($this->receipt->getMimeType(), FinanceReceiptAi::MEDIA_TYPES, true)) {
            $this->receiptMessage = __('Add a photo or PDF of the receipt first.');

            return;
        }

        try {
            $result = $ai->read((string) file_get_contents($this->receipt->getRealPath()), $this->receipt->getMimeType());
        } catch (AiException $exception) {
            $this->receiptMessage = $exception->getMessage();

            return;
        }

        if ($result['booked_on']) {
            $this->invoice['invoice_date'] = $result['booked_on'];
        }

        if ($result['counterparty'] !== '') {
            $this->invoice['vendor'] = $result['counterparty'];
        }

        if ($result['amount'] !== null) {
            $this->invoice['amount'] = (string) $result['amount'];
        }

        if ($result['purpose'] !== '' && $this->invoice['notes'] === '') {
            $this->invoice['notes'] = $result['purpose'];
        }

        $this->receiptMessage = $result['amount'] !== null ? __('Filled in from the receipt – please check.') : __('Nothing could be read from the receipt.');
    }

    public function saveInvoice(): void
    {
        $model = $this->findRealEstate();
        $data = $this->validate([
            'invoice.budget_item_id' => ['nullable', Rule::in($model->budgetItems()->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'invoice.vendor' => ['required', 'string', 'max:255'],
            'invoice.number' => ['nullable', 'string', 'max:100'],
            'invoice.invoice_date' => ['required', 'date'],
            'invoice.due_on' => ['nullable', 'date'],
            'invoice.amount' => ['required', 'numeric', 'min:-100000000', 'max:100000000'],
            'invoice.labor_amount' => ['nullable', 'numeric', 'min:0', 'lte:invoice.amount'],
            'invoice.paid_on' => ['nullable', 'date'],
            'invoice.tax_treatment' => ['required', Rule::in(RealEstateInvoice::TAX_TREATMENTS)],
            'invoice.notes' => ['nullable', 'string', 'max:5000'],
            'receipt' => ['nullable', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,gif,pdf,heic'],
        ], attributes: ['invoice.vendor' => __('Vendor'), 'invoice.amount' => __('Amount (gross, €)'), 'invoice.labor_amount' => __('Labour costs')]);

        $attributes = collect($data['invoice'])->map(fn ($value) => $value === '' ? null : $value)->all();
        $attributes['labor_amount'] = (float) ($attributes['labor_amount'] ?? 0);

        $isNew = ! $this->editingInvoiceId;
        $invoice = $this->editingInvoiceId
            ? tap($model->invoices()->findOrFail($this->editingInvoiceId))->update($attributes)
            : $model->invoices()->create($attributes + ['created_by' => Auth::id()]);

        $paperless = $this->receipt && $this->receiptToPaperless ? \App\Models\PaperlessInstance::defaultFor(Auth::user()) : null;

        if ($paperless) {
            try {
                $taskId = \App\Support\Paperless\PaperlessClient::for($paperless, Auth::user())->upload($this->receipt, trim($invoice->vendor.' '.$invoice->number));
                \App\Models\ExternalLink::create([
                    'household_id' => $this->householdId, 'created_by' => Auth::id(), 'linkable_type' => 'real_estate_invoice', 'linkable_id' => $invoice->id,
                    'kind' => \App\Models\ExternalLink::PAPERLESS, 'paperless_instance_id' => $paperless->id, 'paperless_task_id' => mb_substr($taskId, 0, 64), 'title' => mb_substr(trim($invoice->vendor.' '.$invoice->number), 0, 255),
                ]);
                $this->receipt = null;
            } catch (Throwable $exception) {
                report($exception);
                $this->dispatch('toast', message: __('paperless is not reachable – the receipt was saved in the app.'));
            }
        }

        if ($this->receipt) {
            if ($invoice->receipt_path) {
                \Illuminate\Support\Facades\Storage::disk(RealEstateInvoice::RECEIPT_DISK)->delete($invoice->receipt_path);
            }

            $invoice->update([
                'receipt_path' => $this->receipt->storeAs('real-estate/'.$model->id, Str::uuid().'.'.strtolower($this->receipt->guessExtension() ?: 'bin'), RealEstateInvoice::RECEIPT_DISK),
                'receipt_name' => mb_substr($this->receipt->getClientOriginalName(), 0, 255),
                'receipt_mime' => $this->receipt->getMimeType(),
            ]);
        }

        $this->resetInvoice();

        // A new invoice stays open so further documents (e.g. earlier instalment invoices) can be attached.
        if ($isNew) {
            $this->editInvoice($invoice->id);
            $this->dispatch('toast', message: __('Invoice saved – you can now attach further documents below.'));
        }
    }

    public function markInvoicePaid(int $id): void
    {
        $invoice = $this->findRealEstate()->invoices()->findOrFail($id);
        $invoice->update(['paid_on' => $invoice->paid_on ? null : today()]);
    }

    public function deleteInvoice(int $id): void
    {
        $this->findRealEstate()->invoices()->findOrFail($id)->delete();
        $this->resetInvoice();
    }

    /**
     * Links an invoice to one of the user's bank bookings; the booking date becomes the payment date.
     */
    public function linkTransaction(int $invoiceId, int $transactionId): void
    {
        $invoice = $this->findRealEstate()->invoices()->findOrFail($invoiceId);
        $transaction = Transaction::where('user_id', Auth::id())->where('household_id', $this->householdId)->find($transactionId) ?? abort(404);

        $invoice->update(['transaction_id' => $transaction->id, 'paid_on' => $transaction->booked_on]);
    }

    /**
     * Bank bookings of the user that match an open invoice (same amount, ±30 days).
     *
     * @return array<int, Transaction>
     */
    private function transactionMatches(\Illuminate\Support\Collection $invoices): array
    {
        if (! Auth::user()->canUseAddon(\App\Support\Addons::FINANCE)) {
            return [];
        }

        $matches = [];

        foreach ($invoices->filter(fn (RealEstateInvoice $invoice) => ! $invoice->transaction_id && $invoice->amount > 0) as $invoice) {
            $match = Transaction::where('user_id', Auth::id())->where('household_id', $this->householdId)
                ->where('amount', -$invoice->amount)
                ->whereBetween('booked_on', [$invoice->invoice_date->copy()->subDays(5)->toDateString(), $invoice->invoice_date->copy()->addDays(30)->toDateString()])
                ->whereNotIn('id', RealEstateInvoice::whereNotNull('transaction_id')->select('transaction_id'))
                ->orderBy('booked_on')->first();

            if ($match) {
                $matches[$invoice->id] = $match;
            }
        }

        return $matches;
    }

    // ── Subsidies ────────────────────────────────────────────────────────

    private function resetSubsidy(): void
    {
        $this->editingSubsidyId = null;
        $this->subsidy = ['program' => 'other', 'title' => '', 'kind' => 'grant', 'amount' => '', 'status' => 'idea', 'budget_item_id' => '', 'deadline_on' => '', 'applied_on' => '', 'approved_on' => '', 'paid_on' => '', 'notes' => ''];
    }

    public function useProgram(string $key): void
    {
        $program = SubsidyPrograms::all()[$key] ?? abort(404);
        $this->resetSubsidy();
        $this->subsidy = ['program' => $key, 'title' => $program['title'], 'kind' => $program['kind']] + $this->subsidy;
    }

    public function editSubsidy(int $id): void
    {
        $subsidy = $this->findRealEstate()->subsidies()->findOrFail($id);
        $this->editingSubsidyId = $subsidy->id;
        $this->subsidy = [
            ...collect($subsidy->only(['program', 'title', 'kind', 'status', 'notes']))->map(fn ($value) => (string) $value)->all(),
            'amount' => (string) $subsidy->amount,
            'budget_item_id' => (string) $subsidy->budget_item_id,
            ...collect(['deadline_on', 'applied_on', 'approved_on', 'paid_on'])->mapWithKeys(fn ($field) => [$field => (string) $subsidy->{$field}?->toDateString()])->all(),
        ];
    }

    public function cancelSubsidy(): void
    {
        $this->resetSubsidy();
    }

    public function saveSubsidy(): void
    {
        $model = $this->findRealEstate();
        $data = $this->validate([
            'subsidy.program' => ['required', 'string', 'max:40'],
            'subsidy.title' => ['required', 'string', 'max:255'],
            'subsidy.kind' => ['required', Rule::in(RealEstateSubsidy::KINDS)],
            'subsidy.amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'subsidy.status' => ['required', Rule::in(RealEstateSubsidy::STATUSES)],
            'subsidy.budget_item_id' => ['nullable', Rule::in($model->budgetItems()->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'subsidy.deadline_on' => ['nullable', 'date'],
            'subsidy.applied_on' => ['nullable', 'date'],
            'subsidy.approved_on' => ['nullable', 'date'],
            'subsidy.paid_on' => ['nullable', 'date'],
            'subsidy.notes' => ['nullable', 'string', 'max:5000'],
        ], attributes: ['subsidy.title' => __('Programme')])['subsidy'];

        $attributes = collect($data)->map(fn ($value) => $value === '' ? null : $value)->all();
        $attributes['amount'] = (float) ($attributes['amount'] ?? 0);

        if ($this->editingSubsidyId) {
            $model->subsidies()->findOrFail($this->editingSubsidyId)->update($attributes);
        } else {
            $model->subsidies()->create($attributes);
        }

        $this->resetSubsidy();
    }

    public function setSubsidyStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, RealEstateSubsidy::STATUSES, true), 422);
        $date = ['applied' => 'applied_on', 'approved' => 'approved_on', 'paid' => 'paid_on'][$status] ?? null;
        $subsidy = $this->findRealEstate()->subsidies()->findOrFail($id);
        $subsidy->update(['status' => $status] + ($date && ! $subsidy->{$date} ? [$date => today()] : []));
    }

    public function deleteSubsidy(int $id): void
    {
        $this->findRealEstate()->subsidies()->findOrFail($id)->delete();
        $this->resetSubsidy();
    }

    public function deleteRealEstate(): void
    {
        $model = $this->findRealEstate();
        abort_unless($model->created_by === null || $model->created_by === Auth::id() || $model->household->members()->where('user_id', Auth::id())->first()?->isOwner(), 403);

        $model->delete();
        $this->redirectRoute('real-estate.index', navigate: true);
    }

    public function with(): array
    {
        $model = $this->findRealEstate()->load(['budgetItems.invoices', 'invoices.budgetItem', 'invoices.transaction', 'loans', 'subsidies.budgetItem']);
        $summary = RealEstatePlan::summary($model);
        $firstYear = $model->purchase_date?->year ?? (int) now()->year;

        return [
            'model' => $model,
            'summary' => $summary,
            'tax' => in_array($this->tab, ['overview', 'tax'], true) ? RealEstateTax::forYear($model, $this->taxYear) : null,
            'taxYears' => range(min($firstYear, (int) now()->year), (int) now()->year + 3),
            'suggestions' => $this->tab === 'subsidies' ? SubsidyPrograms::suggestionsFor($model) : [],
            'programs' => SubsidyPrograms::all(),
            'schedule' => $this->scheduleLoanId && ($scheduleLoan = $model->loans->firstWhere('id', $this->scheduleLoanId)) ? RealEstatePlan::schedule($scheduleLoan) : [],
            'transactionMatches' => $this->tab === 'invoices' ? $this->transactionMatches($model->invoices) : [],
            'invoiceDocumentCounts' => $this->tab === 'invoices'
                ? \App\Models\ExternalLink::where('linkable_type', 'real_estate_invoice')->whereIn('linkable_id', $model->invoices->pluck('id'))->selectRaw('linkable_id, count(*) as total')->groupBy('linkable_id')->pluck('total', 'linkable_id')
                : collect(),
            'deadlines' => collect()
                ->concat($model->invoices->reject->isPaid()->filter(fn ($invoice) => $invoice->due_on)->map(fn ($invoice) => ['date' => $invoice->due_on, 'label' => __('Invoice :vendor due', ['vendor' => $invoice->vendor]), 'overdue' => $invoice->isOverdue()]))
                ->concat($model->subsidies->whereIn('status', ['idea', 'applied'])->filter(fn ($subsidy) => $subsidy->deadline_on)->map(fn ($subsidy) => ['date' => $subsidy->deadline_on, 'label' => __('Deadline :program', ['program' => $subsidy->title]), 'overdue' => $subsidy->deadline_on->lt(today())]))
                ->concat($model->budgetItems->where('status', '!=', 'done')->filter(fn ($item) => $item->start_on && $item->start_on->gte(today()->subDays(7)))->map(fn ($item) => ['date' => $item->start_on, 'label' => __('Start: :trade', ['trade' => $item->title]), 'overdue' => false]))
                ->sortBy('date')->take(8)->values(),
        ];
    }
}; ?>

@php
    $euro = fn ($value, $decimals = 0) => number_format((float) $value, $decimals, ',', '.').' €';
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <a href="{{ route('real-estate.index') }}" wire:navigate class="text-sm text-gray-500 hover:underline dark:text-gray-400">← {{ __('Real estate') }}</a>
                <h2 class="truncate font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🏠 {{ $model->name }} @if ($model->isPrivate())<span title="{{ __('Only for me') }}">🔒</span>@endif</h2>
            </div>
            <span class="rounded-full bg-indigo-50 px-3 py-1 text-sm text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ \App\Models\RealEstate::statusLabels()[$model->status] ?? $model->status }} · {{ \App\Models\RealEstate::usageLabels()[$model->usage] }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            <nav class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="{{ __('Sections') }}">
                <div class="inline-flex min-w-max rounded-lg bg-white p-1 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" role="tablist">
                    @foreach (['overview' => '📊 '.__('Overview'), 'purchase' => '🏷 '.__('Purchase & financing'), 'budget' => '🧱 '.__('Budget & trades'), 'invoices' => '🧾 '.__('Invoices'), 'subsidies' => '🎁 '.__('Subsidies'), 'rental' => '🔑 '.__('Letting'), 'value' => '📈 '.__('Value development'), 'tax' => '🏛 '.__('Tax')] as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['rounded-md px-3 py-1.5 text-sm whitespace-nowrap', 'bg-indigo-600 text-white' => $tab === $key, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $tab !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </nav>

            @include('real-estate.partials.'.$tab)
        </div>
    </div>
</div>
