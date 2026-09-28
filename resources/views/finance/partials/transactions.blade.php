{{-- Finance bookings tab (variables from finance/index: $transactions, $transactionCount, $rules, $categories, $euro, $aiAvailable). --}}
@php
    $groupLabels = ['income' => __('Income'), 'needs' => __('Needs'), 'wants' => __('Wishes & lifestyle'), 'savings' => __('Saving'), 'transfer' => __('Transfers')];
    $groupedCategories = collect($categories)->groupBy('group', preserveKeys: true);
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search payee or purpose …') }}" aria-label="{{ __('Search') }}"
               class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
        <select wire:model.live="categoryFilter" aria-label="{{ __('Category') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
            <option value="">{{ __('All categories') }}</option>
            @foreach ($groupedCategories as $group => $items)
                <optgroup label="{{ $groupLabels[$group] ?? $group }}">
                    @foreach ($items as $key => $category)
                        <option value="{{ $key }}">{{ $category['icon'] }} {{ $category['label'] }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        @unless ($manualOpen)
            <button type="button" wire:click="openManual" class="rounded-lg px-3 py-2 text-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700">🧾 {{ __('Add receipt') }}</button>
        @endunless
        @if ($aiAvailable)
            <button type="button" wire:click="categorizeWithAi" wire:loading.attr="disabled" wire:target="categorizeWithAi"
                    class="rounded-lg px-3 py-2 text-sm text-indigo-600 ring-1 ring-indigo-200 hover:bg-indigo-50 disabled:opacity-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">
                <span wire:loading.remove wire:target="categorizeWithAi">✨ {{ __('AI: categorize open bookings') }}</span>
                <span wire:loading wire:target="categorizeWithAi">{{ __('Categorizing …') }}</span>
            </button>
        @endif
    </div>

    @if ($manualSaved && ! $manualOpen)
        <p class="rounded-xl bg-green-50 px-4 py-2.5 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200" role="status">✓ {{ __('Expense saved.') }}</p>
    @endif

    @if ($manualOpen)
        <x-drop-zone model="receiptPhoto" :label="__('Drop the receipt here')">
        <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="manual-heading">
            <div class="flex items-center justify-between gap-2">
                <h3 id="manual-heading" class="font-semibold text-gray-900 dark:text-gray-100">🧾 {{ __('Add receipt or cash expense') }}</h3>
                <button type="button" wire:click="closeManual" class="text-sm text-gray-500 hover:text-gray-800 dark:hover:text-gray-200" aria-label="{{ __('Close') }}">✕</button>
            </div>

            @if ($aiAvailable)
                <div class="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-indigo-50/60 p-3 dark:bg-indigo-900/20">
                    <label class="cursor-pointer rounded-lg bg-white px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-600">
                        📷 {{ $receiptPhoto ? __('Other photo') : __('Photo of the receipt') }}
                        <input type="file" wire:model="receiptPhoto" accept="image/*,application/pdf" capture="environment" class="sr-only">
                    </label>
                    <button type="button" wire:click="readReceiptPhoto" wire:loading.attr="disabled" wire:target="readReceiptPhoto,receiptPhoto" @disabled(! $receiptPhoto) class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-500 disabled:opacity-50">
                        <span wire:loading.remove wire:target="readReceiptPhoto">✨ {{ __('Read with AI') }}</span>
                        <span wire:loading wire:target="readReceiptPhoto">{{ __('Reading …') }}</span>
                    </button>
                    <span wire:loading wire:target="receiptPhoto" class="text-xs text-gray-500">{{ __('Uploading …') }}</span>
                    @if ($receiptMessage)
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $receiptMessage }}</span>
                    @endif
                </div>
            @endif

            <form wire:submit="saveManual" class="mt-4 grid gap-3 sm:grid-cols-2">
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Date') }}
                    <input type="date" wire:model="manual.booked_on" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <x-input-error :messages="$errors->get('manual.booked_on')" />
                </label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Amount') }} (€)
                    <input type="text" inputmode="decimal" wire:model="manual.amount" placeholder="12,34" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <x-input-error :messages="$errors->get('manual.amount')" />
                </label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Shop / payee') }}
                    <input type="text" wire:model="manual.counterparty" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <x-input-error :messages="$errors->get('manual.counterparty')" />
                </label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('What for?') }}
                    <input type="text" wire:model="manual.purpose" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                </label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Category') }}
                    <select wire:model="manual.category" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        @foreach ($groupedCategories as $group => $items)
                            @continue(in_array($group, ['income', 'transfer'], true))
                            <optgroup label="{{ $groupLabels[$group] ?? $group }}">
                                @foreach ($items as $key => $category)
                                    <option value="{{ $key }}">{{ $category['icon'] }} {{ $category['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Paid from') }}
                    <select wire:model="manual.account" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="cash">💵 {{ __('Cash wallet') }}</option>
                        @foreach ($accounts as $account)
                            @continue($account->name === __('Cash wallet'))
                            <option value="{{ $account->id }}">🏦 {{ $account->name }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($matchingTransaction)
                    <div class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900 sm:col-span-2 dark:bg-amber-900/30 dark:text-amber-100" role="alert">
                        <p>{{ __('This purchase is probably already booked:') }} <strong>{{ $matchingTransaction->booked_on->format('d.m.Y') }} · {{ $matchingTransaction->counterparty }} · {{ $euro($matchingTransaction->amount) }}</strong></p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <x-primary-button type="button" wire:click="saveManual('assign')">{{ __('Only set the category there') }}</x-primary-button>
                            <x-secondary-button type="button" wire:click="saveManual('new')">{{ __('Book separately anyway') }}</x-secondary-button>
                        </div>
                    </div>
                @else
                    <div class="flex gap-2 sm:col-span-2">
                        <x-primary-button>{{ __('Save expense') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="closeManual">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                @endif
            </form>
        </section>
        </x-drop-zone>
    @endif

    @if ($aiError)
        <p class="rounded-xl bg-red-50 px-4 py-2.5 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">{{ $aiError }}</p>
    @endif

    <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
        <p class="border-b border-gray-100 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
            {{ trans_choice(':count booking|:count bookings', $transactionCount) }} · {{ __('Change a category to correct it – with “always” the payee is remembered for future imports.') }}
        </p>
        @if ($transactions->isEmpty())
            <p class="p-6 text-sm text-gray-600 dark:text-gray-300">{{ __('No bookings found.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($transactions as $transaction)
                    <li wire:key="tx-{{ $transaction->id }}" @class(["flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3", "sm:flex-nowrap" => $documentsFor !== $transaction->id]) x-data="{ remember: false }">
                        <div class="w-16 shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $transaction->booked_on->format('d.m.y') }}</div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $transaction->counterparty ?: '—' }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $transaction->purpose }}">{{ $transaction->purpose }}@if ($accounts->count() > 1) · {{ $transaction->account?->name }}@endif</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <select aria-label="{{ __('Category') }}"
                                    x-on:change="$wire.setCategory({{ $transaction->id }}, $event.target.value, remember)"
                                    class="w-52 max-w-full rounded-lg border-gray-200 py-1 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                @foreach ($groupedCategories as $group => $items)
                                    <optgroup label="{{ $groupLabels[$group] ?? $group }}">
                                        @foreach ($items as $key => $category)
                                            <option value="{{ $key }}" @selected($transaction->category === $key)>{{ $category['icon'] }} {{ $category['label'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @if ($transaction->amount < 0)
                                <select aria-label="{{ __('Tax category') }}" wire:change="setTaxCategory({{ $transaction->id }}, $event.target.value)"
                                        @class(['w-32 rounded-lg py-1 text-xs dark:bg-gray-900 dark:text-gray-200', 'border-amber-300 bg-amber-50 dark:border-amber-700' => $transaction->tax_category, 'border-gray-200 text-gray-500 dark:border-gray-600' => ! $transaction->tax_category])>
                                    <option value="">🏛 {{ __('Tax …') }}</option>
                                    @foreach (\App\Support\Finance\TaxCategories::all() as $taxKey => $taxCategory)
                                        <option value="{{ $taxKey }}" @selected($transaction->tax_category === $taxKey)>{{ $taxCategory['icon'] }} {{ $taxCategory['label'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400" title="{{ __('Remember for this payee') }}">
                                <input type="checkbox" x-model="remember" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                                {{ __('always') }}
                            </label>
                        </div>
                        <div @class(['w-24 shrink-0 text-end text-sm font-semibold tabular-nums', 'text-gray-900 dark:text-gray-100' => $transaction->amount < 0, 'text-emerald-700 dark:text-emerald-400' => $transaction->amount >= 0])>
                            {{ $transaction->amount >= 0 ? '+' : '' }}{{ $euro($transaction->amount) }}
                        </div>
                        <button type="button" wire:click="$set('documentsFor', {{ $documentsFor === $transaction->id ? 'null' : $transaction->id }})" @class(['shrink-0 text-sm', 'text-indigo-600' => $documentsFor === $transaction->id, 'text-gray-400 hover:text-indigo-600' => $documentsFor !== $transaction->id]) title="{{ __('Documents & e-mails') }}" aria-label="{{ __('Documents & e-mails') }}" aria-expanded="{{ $documentsFor === $transaction->id ? 'true' : 'false' }}">🗂</button>
                        @if ($documentsFor === $transaction->id)
                            <div class="w-full rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
                                <livewire:documents-panel type="transaction" :id="$transaction->id" :wire:key="'documents-transaction-'.$transaction->id" />
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($transactionCount > $transactions->count())
                <div class="border-t border-gray-100 p-3 text-center dark:border-gray-700">
                    <button type="button" wire:click="$set('limit', {{ $limit + 100 }})" class="text-sm text-indigo-600 hover:underline dark:text-indigo-300">{{ __('Show more') }}</button>
                </div>
            @endif
        @endif
    </section>

    @if ($rules->isNotEmpty())
        <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Remembered categories') }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Bookings containing this text always get this category.') }}</p>
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach ($rules as $rule)
                    <li wire:key="rule-{{ $rule->id }}" class="flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pe-1 ps-3 text-xs text-gray-800 dark:bg-gray-700 dark:text-gray-200">
                        „{{ $rule->pattern }}“ → {{ \App\Support\Finance\FinanceCategories::icon($rule->category) }} {{ \App\Support\Finance\FinanceCategories::label($rule->category) }}
                        <button type="button" wire:click="deleteRule({{ $rule->id }})" class="rounded-full px-1.5 text-gray-500 hover:bg-gray-200 hover:text-gray-900 dark:hover:bg-gray-600" aria-label="{{ __('Delete') }}">✕</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
