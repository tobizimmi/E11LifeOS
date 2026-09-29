<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\BankAccount;
use App\Models\BookkeepingEntry;
use App\Models\BusinessProfile;
use App\Models\ExternalLink;
use App\Models\PaperlessInstance;
use App\Models\RealEstate;
use App\Models\RealEstateInvoice;
use App\Models\Transaction;
use App\Support\AiException;
use App\Support\Business\BookkeepingCategories;
use App\Support\Business\ReceiptAi;
use App\Support\Finance\FinanceCategories;
use App\Support\Finance\FinanceReceiptAi;
use App\Support\Paperless\PaperlessClient;
use App\Support\Paperless\PaperlessException;
use App\Support\Paperless\PaperlessInbox;
use App\Support\QuickCapture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

/**
 * The paperless inbox inside the app inbox: documents that still carry an inbox tag in
 * paperless (scans, mail attachments from a paperless mail rule) are filed as a private
 * expense, a property invoice, a business receipt or a task – the document stays in paperless
 * and is only linked.
 */
new class extends Component
{
    use InteractsWithHousehold;

    public ?int $instanceId = null;

    public ?int $assigningId = null;

    public string $assigningTitle = '';

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    public ?string $message = null;

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->instanceId = PaperlessInstance::defaultFor($this->currentUser())?->id;
    }

    private function instance(): PaperlessInstance
    {
        return PaperlessInstance::query()->availableTo($this->currentUser())->find($this->instanceId) ?? abort(404);
    }

    private function client(): PaperlessClient
    {
        return PaperlessClient::for($this->instance(), $this->currentUser());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, RealEstate>
     */
    private function realEstates()
    {
        return RealEstate::query()->visibleTo($this->currentUser(), $this->householdId)->orderBy('name')->get();
    }

    public function updatedInstanceId(): void
    {
        $this->instance();
        $this->reset('assigningId', 'form', 'message');
    }

    /**
     * Opens the filing form, preselected from the document's tags and title.
     */
    public function assign(int $documentId): void
    {
        $this->resetValidation();
        $this->message = null;
        $user = $this->currentUser();

        try {
            $client = $this->client();
            $document = $client->document($documentId);
            $tagNames = array_values(array_intersect_key($client->tags(), array_flip($document['tags'])));
        } catch (PaperlessException $exception) {
            $this->message = $exception->getMessage();

            return;
        }

        $targets = PaperlessInbox::targetsFor($user);
        $suggestion = PaperlessInbox::suggest($document['title'], $tagNames, $this->realEstates(), $targets);

        $this->assigningId = $documentId;
        $this->assigningTitle = $document['title'];
        $this->form = [
            'target' => $suggestion['target'],
            'real_estate_id' => $suggestion['real_estate_id'] ?? $this->realEstates()->first()?->id,
            'date' => $document['created'] ?? today()->toDateString(),
            'counterparty' => '',
            'description' => $document['title'],
            'amount' => '',
            'vat_amount' => '',
            'category' => FinanceCategories::OTHER,
            'business_category' => array_key_first(BookkeepingCategories::forKind(BookkeepingEntry::EXPENSE)),
            'account' => 'cash',
            'remove_from_inbox' => true,
        ];
    }

    /**
     * A document dropped onto one of the targets: opens the filing form with that target.
     */
    public function assignTo(int $documentId, string $target): void
    {
        $this->assign($documentId);

        if ($this->assigningId === $documentId && in_array($target, PaperlessInbox::targetsFor($this->currentUser()), true)) {
            $this->form['target'] = $target;
        }
    }

    public function cancel(): void
    {
        $this->reset('assigningId', 'assigningTitle', 'form');
        $this->resetValidation();
    }

    /**
     * Lets the AI read date, payee, amount and category from the paperless document.
     */
    public function prefill(FinanceReceiptAi $financeAi, ReceiptAi $businessAi): void
    {
        abort_if($this->assigningId === null, 404);
        $this->message = null;

        try {
            $response = $this->client()->file($this->assigningId, 'preview');
        } catch (Throwable) {
            $this->message = __('The document could not be loaded from paperless.');

            return;
        }

        $mediaType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if (! in_array($mediaType, FinanceReceiptAi::MEDIA_TYPES, true)) {
            $this->message = __('The AI can only read PDFs and images.');

            return;
        }

        try {
            if ($this->form['target'] === 'business') {
                $result = $businessAi->read($response->body(), $mediaType, BookkeepingEntry::EXPENSE);
                $result['purpose'] = $result['description'];
            } else {
                $result = $financeAi->read($response->body(), $mediaType);
            }
        } catch (AiException $exception) {
            $this->message = $exception->getMessage();

            return;
        }

        $this->form['date'] = $result['booked_on'] ?: $this->form['date'];
        $this->form['counterparty'] = $result['counterparty'] ?: $this->form['counterparty'];
        $this->form['description'] = $result['purpose'] ?: $this->form['description'];

        if ($result['amount'] !== null) {
            $this->form['amount'] = number_format($result['amount'], 2, ',', '');
        }

        if ($this->form['target'] === 'business') {
            $this->form['business_category'] = $result['category'] ?: $this->form['business_category'];
            $this->form['vat_amount'] = $result['vat_amount'] !== null ? number_format($result['vat_amount'], 2, ',', '') : '';
        } elseif ($result['category']) {
            $this->form['category'] = $result['category'];
        }

        $this->message = $result['amount'] !== null ? __('Filled in from the document – please check.') : __('Nothing could be read from the document.');
    }

    /**
     * Creates the booking, invoice or task, links the paperless document to it and (by
     * default) takes it out of the paperless inbox.
     */
    public function save(): void
    {
        abort_if($this->assigningId === null, 404);
        $user = $this->currentUser();
        $instance = $this->instance();
        $targets = PaperlessInbox::targetsFor($user);

        foreach (['amount', 'vat_amount'] as $field) {
            $this->form[$field] = str_replace(['€', ' ', ','], ['', '', '.'], trim((string) ($this->form[$field] ?? '')));
        }

        $target = (string) ($this->form['target'] ?? '');
        $needsAmount = $target !== 'task';
        $data = $this->validate([
            'form.target' => ['required', Rule::in($targets)],
            'form.description' => ['required', 'string', 'max:255'],
            'form.date' => [Rule::requiredIf($needsAmount), 'nullable', 'date'],
            'form.counterparty' => [Rule::requiredIf(in_array($target, ['finance', 'real_estate'], true)), 'nullable', 'string', 'max:255'],
            'form.amount' => [Rule::requiredIf($needsAmount), 'nullable', 'numeric', 'gt:0', 'max:9999999'],
            'form.vat_amount' => ['nullable', 'numeric', 'min:0', 'lt:form.amount'],
            'form.category' => [Rule::requiredIf($target === 'finance'), 'nullable', Rule::in(array_keys(FinanceCategories::all()))],
            'form.business_category' => [Rule::requiredIf($target === 'business'), 'nullable', fn ($attribute, $value, $fail) => $target !== 'business' || BookkeepingCategories::exists((string) $value, BookkeepingEntry::EXPENSE) ? null : $fail(__('Please choose a category.'))],
            'form.real_estate_id' => [Rule::requiredIf($target === 'real_estate'), 'nullable', 'integer'],
            'form.account' => ['nullable', 'string'],
            'form.remove_from_inbox' => ['boolean'],
        ], attributes: [
            'form.description' => __('Description'),
            'form.amount' => __('Amount'),
            'form.counterparty' => __('Shop / payee'),
            'form.date' => __('Date'),
        ])['form'];

        $amount = $needsAmount ? round((float) $data['amount'], 2) : 0.0;

        [$linkableType, $linkableId] = DB::transaction(function () use ($data, $target, $amount, $user, $instance) {
            return match ($target) {
                'finance' => ['transaction', $this->createTransaction($data, $amount)->id],
                'real_estate' => ['real_estate_invoice', RealEstateInvoice::create([
                    'real_estate_id' => ($this->realEstates()->firstWhere('id', (int) $data['real_estate_id']) ?? abort(404))->id,
                    'created_by' => $user->id,
                    'vendor' => trim((string) $data['counterparty']),
                    'invoice_date' => $data['date'],
                    'amount' => $amount,
                    'tax_treatment' => 'auto',
                    'notes' => trim($data['description']),
                ])->id],
                'business' => ['bookkeeping_entry', BookkeepingEntry::create([
                    'user_id' => $user->id,
                    'household_id' => $this->householdId,
                    'kind' => BookkeepingEntry::EXPENSE,
                    'booked_on' => $data['date'],
                    'description' => trim($data['description']),
                    'counterparty' => filled($data['counterparty']) ? trim($data['counterparty']) : null,
                    'category' => $data['business_category'],
                    'amount' => $amount,
                    'vat_amount' => (BusinessProfile::where('user_id', $user->id)->first()?->small_business ?? true) ? 0 : round((float) ($data['vat_amount'] ?: 0), 2),
                ])->id],
                default => ['item', (QuickCapture::task($user, $this->householdId, $data['description'], private: ! $instance->shared) ?? abort(422))->id],
            };
        });

        ExternalLink::create([
            'household_id' => $this->householdId,
            'created_by' => $user->id,
            'linkable_type' => $linkableType,
            'linkable_id' => $linkableId,
            'kind' => ExternalLink::PAPERLESS,
            'paperless_instance_id' => $instance->id,
            'paperless_document_id' => $this->assigningId,
            'title' => mb_substr($this->assigningTitle ?: $data['description'], 0, 255),
        ]);

        $this->message = __('Filed and linked.');

        if ($data['remove_from_inbox']) {
            try {
                $this->client()->removeFromInbox($this->assigningId);
            } catch (Throwable) {
                $this->message = __('Filed and linked, but paperless could not remove the inbox tag.');
            }
        }

        $this->reset('assigningId', 'assigningTitle', 'form');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTransaction(array $data, float $amount): Transaction
    {
        $user = $this->currentUser();
        $accounts = BankAccount::where('user_id', $user->id)->where('household_id', $this->householdId);
        $account = ctype_digit((string) $data['account'])
            ? ($accounts->find((int) $data['account']) ?? abort(404))
            : BankAccount::firstOrCreate(['user_id' => $user->id, 'household_id' => $this->householdId, 'name' => __('Cash wallet')]);

        return Transaction::create([
            'household_id' => $this->householdId,
            'user_id' => $user->id,
            'bank_account_id' => $account->id,
            'booked_on' => $data['date'],
            'amount' => -$amount,
            'counterparty' => trim((string) $data['counterparty']),
            'purpose' => trim($data['description']),
            'category' => $data['category'],
            'category_locked' => true,
            'import_hash' => sha1('paperless|'.Str::uuid()),
        ]);
    }

    /**
     * Only removes the inbox tag in paperless (nothing to file).
     */
    public function dismiss(int $documentId): void
    {
        try {
            $this->client()->removeFromInbox($documentId);
            $this->message = __('Removed from the paperless inbox.');
        } catch (Throwable) {
            $this->message = __('paperless could not remove the inbox tag.');
        }

        if ($this->assigningId === $documentId) {
            $this->cancel();
        }
    }

    public function with(): array
    {
        $user = $this->currentUser();
        $instances = PaperlessInstance::query()->availableTo($user)->orderBy('name')->get();
        $documents = [];
        $tagNames = [];
        $error = null;

        if ($this->instanceId !== null && $instances->contains('id', $this->instanceId)) {
            try {
                $client = $this->client();
                $documents = $client->inbox();
                $tagNames = $documents === [] ? [] : $client->tags();
            } catch (PaperlessException $exception) {
                $error = $exception->getMessage();
            }
        }

        $targets = PaperlessInbox::targetsFor($user);
        $realEstates = in_array('real_estate', $targets, true) ? $this->realEstates() : collect();
        $linked = ExternalLink::where('paperless_instance_id', $this->instanceId)->whereIn('paperless_document_id', array_column($documents, 'id'))->pluck('paperless_document_id')->all();

        return [
            'instances' => $instances,
            'documents' => collect($documents)->map(fn (array $document) => $document + [
                'tag_names' => array_values(array_intersect_key($tagNames, array_flip($document['tags']))),
                'suggestion' => PaperlessInbox::suggest($document['title'], array_values(array_intersect_key($tagNames, array_flip($document['tags']))), $realEstates, $targets)['target'],
                'linked' => in_array($document['id'], $linked, true),
            ])->all(),
            'error' => $error,
            'targets' => $targets,
            'realEstates' => $realEstates,
            'accounts' => in_array('finance', $targets, true) ? BankAccount::where('user_id', $user->id)->where('household_id', $this->householdId)->orderBy('name')->get() : collect(),
            'financeCategories' => collect(FinanceCategories::all())->reject(fn (array $category) => in_array($category['group'], ['income', 'transfer'], true)),
            'businessCategories' => BookkeepingCategories::forKind(BookkeepingEntry::EXPENSE),
            'targetLabels' => ['finance' => __('Private expense'), 'real_estate' => __('Property invoice'), 'business' => __('Business receipt'), 'task' => __('Task')],
        ];
    }
}; ?>

<div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-semibold text-gray-900 dark:text-gray-100">🗂 {{ __('paperless inbox') }}</h3>
        @if ($instances->count() > 1)
            <select wire:model.live="instanceId" aria-label="{{ __('paperless instance') }}" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                @foreach ($instances as $paperlessInstance)
                    <option value="{{ $paperlessInstance->id }}">{{ $paperlessInstance->name }}</option>
                @endforeach
            </select>
        @endif
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('New scans and mail attachments from paperless: file them as an expense, invoice, receipt or task. The documents stay in paperless.') }}</p>

    @if ($message)
        <p class="rounded-md bg-gray-50 dark:bg-gray-900/50 px-3 py-2 text-sm text-gray-700 dark:text-gray-300" role="status">{{ $message }}</p>
    @endif

    @if ($error)
        <p class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif

    @if ($documents !== [])
        {{-- Drag a document onto a target to file it there (desktop); the buttons below do the same. --}}
        <div class="hidden flex-wrap gap-2 sm:flex" aria-hidden="true">
            @foreach ($targets as $target)
                <div
                    x-data="{ over: false }"
                    x-on:dragover="if ([...$event.dataTransfer.types].includes('application/x-paperless-document')) { $event.preventDefault(); over = true }"
                    x-on:dragleave="over = false"
                    x-on:drop="over = false; const id = $event.dataTransfer.getData('application/x-paperless-document'); if (id) { $event.preventDefault(); $wire.assignTo(parseInt(id), @js($target)) }"
                    :class="over ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300'"
                    class="flex-1 rounded-lg border-2 border-dashed px-3 py-2 text-center text-sm"
                >{{ $targetLabels[$target] }}</div>
            @endforeach
        </div>
        <p class="hidden text-xs text-gray-500 sm:block dark:text-gray-400">{{ __('Tip: drag a document onto one of the fields above.') }}</p>
    @endif

    <ul class="space-y-3">
        @forelse ($documents as $paperlessDocument)
            <li wire:key="paperless-inbox-{{ $paperlessDocument['id'] }}" draggable="true" x-data x-on:dragstart="$event.dataTransfer.setData('application/x-paperless-document', '{{ $paperlessDocument['id'] }}'); $event.dataTransfer.effectAllowed = 'move'" class="rounded-md border border-gray-200 dark:border-gray-700 p-3">
                <div class="flex gap-3">
                    <a href="{{ route('paperless.document', [$instanceId, $paperlessDocument['id'], 'preview']) }}" target="_blank" rel="noopener" draggable="false" class="shrink-0">
                        <img draggable="false" src="{{ route('paperless.document', [$instanceId, $paperlessDocument['id'], 'thumb']) }}" alt="" loading="lazy" class="h-20 w-16 rounded border border-gray-200 dark:border-gray-700 object-cover bg-gray-50">
                    </a>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-900 dark:text-gray-100 break-words">{{ $paperlessDocument['title'] }}</p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $paperlessDocument['created'] ? \Illuminate\Support\Carbon::parse($paperlessDocument['created'])->format('d.m.Y') : '' }}
                            @if ($paperlessDocument['linked'])
                                · ✓ {{ __('already linked') }}
                            @endif
                        </p>
                        @if ($paperlessDocument['tag_names'] !== [])
                            <p class="mt-1 flex flex-wrap gap-1">
                                @foreach ($paperlessDocument['tag_names'] as $tagName)
                                    <span class="rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-600 dark:text-gray-300">{{ $tagName }}</span>
                                @endforeach
                            </p>
                        @endif
                        @if ($paperlessDocument['content'] !== '')
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 line-clamp-2">{{ $paperlessDocument['content'] }}</p>
                        @endif
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            <x-secondary-button type="button" wire:click="assign({{ $paperlessDocument['id'] }})">{{ __('File away') }} · {{ $targetLabels[$paperlessDocument['suggestion']] }}</x-secondary-button>
                            <button type="button" wire:click="dismiss({{ $paperlessDocument['id'] }})" class="text-xs text-gray-500 underline">{{ __('Remove from inbox') }}</button>
                        </div>
                    </div>
                </div>

                @if ($assigningId === $paperlessDocument['id'])
                    <form wire:submit="save" class="mt-3 space-y-3 border-t border-gray-100 dark:border-gray-700 pt-3">
                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('File as') }}">
                            @foreach ($targets as $target)
                                <label @class(['cursor-pointer rounded-full border px-3 py-1 text-sm', 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' => ($form['target'] ?? '') === $target, 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300' => ($form['target'] ?? '') !== $target])>
                                    <input type="radio" wire:model.live="form.target" value="{{ $target }}" class="sr-only"> {{ $targetLabels[$target] }}
                                </label>
                            @endforeach
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <x-input-label for="inbox-description" :value="($form['target'] ?? '') === 'task' ? __('Task') : __('Description')" />
                                <x-text-input id="inbox-description" wire:model="form.description" class="mt-1 block w-full" />
                                <x-input-error :messages="$errors->get('form.description')" class="mt-1" />
                            </div>

                            @if (($form['target'] ?? '') !== 'task')
                                @if ($form['target'] === 'real_estate')
                                    <div>
                                        <x-input-label for="inbox-real-estate" :value="__('Object')" />
                                        <select id="inbox-real-estate" wire:model="form.real_estate_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                            @foreach ($realEstates as $realEstate)
                                                <option value="{{ $realEstate->id }}">{{ $realEstate->name }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('form.real_estate_id')" class="mt-1" />
                                    </div>
                                @endif
                                <div>
                                    <x-input-label for="inbox-counterparty" :value="__('Shop / payee')" />
                                    <x-text-input id="inbox-counterparty" wire:model="form.counterparty" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('form.counterparty')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="inbox-date" :value="__('Date')" />
                                    <x-text-input id="inbox-date" type="date" wire:model="form.date" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('form.date')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="inbox-amount" :value="__('Amount (gross, €)')" />
                                    <x-text-input id="inbox-amount" inputmode="decimal" wire:model="form.amount" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('form.amount')" class="mt-1" />
                                </div>
                                @if ($form['target'] === 'finance')
                                    <div>
                                        <x-input-label for="inbox-category" :value="__('Category')" />
                                        <select id="inbox-category" wire:model="form.category" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                            @foreach ($financeCategories as $key => $category)
                                                <option value="{{ $key }}">{{ $category['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label for="inbox-account" :value="__('Account')" />
                                        <select id="inbox-account" wire:model="form.account" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                            <option value="cash">{{ __('Cash wallet') }}</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @elseif ($form['target'] === 'business')
                                    <div>
                                        <x-input-label for="inbox-business-category" :value="__('Category')" />
                                        <select id="inbox-business-category" wire:model="form.business_category" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                            @foreach ($businessCategories as $key => $category)
                                                <option value="{{ $key }}">{{ $category['icon'] }} {{ $category['label'] }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('form.business_category')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="inbox-vat" :value="__('VAT')" />
                                        <x-text-input id="inbox-vat" inputmode="decimal" wire:model="form.vat_amount" class="mt-1 block w-full" />
                                        <x-input-error :messages="$errors->get('form.vat_amount')" class="mt-1" />
                                    </div>
                                @endif
                            @endif
                        </div>

                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" wire:model="form.remove_from_inbox" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            {{ __('Remove the inbox tag in paperless') }}
                        </label>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-primary-button>{{ __('File and link') }}</x-primary-button>
                            @if (($form['target'] ?? '') !== 'task' && \App\Support\StructuredAi::isConfigured())
                                <x-secondary-button type="button" wire:click="prefill" wire:loading.attr="disabled" wire:target="prefill">✨ {{ __('Read with AI') }}</x-secondary-button>
                            @endif
                            <button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">{{ __('Cancel') }}</button>
                        </div>
                    </form>
                @endif
            </li>
        @empty
            @unless ($error)
                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('The paperless inbox is empty.') }}</li>
            @endunless
        @endforelse
    </ul>

    <details class="text-sm text-gray-600 dark:text-gray-400">
        <summary class="cursor-pointer">📧 {{ __('Forward mails into the inbox') }}</summary>
        <ol class="mt-2 list-decimal space-y-1 ps-5">
            <li>{{ __('In paperless, add your mailbox under Mail → Mail accounts (IMAP).') }}</li>
            <li>{{ __('Create a mail rule that consumes attachments (or the whole mail as PDF) and assigns your inbox tag.') }}</li>
            <li>{{ __('Forward invoices and letters to that mailbox – they appear here within a few minutes and can be filed with one click.') }}</li>
        </ol>
    </details>
</div>
