{{-- Finance import tab (variables from finance/index: $accounts, $importResult, $importError). --}}
<div class="grid gap-5 lg:grid-cols-5">
    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800 lg:col-span-3">
        <h3 class="font-semibold text-gray-900 dark:text-gray-100">⬆ {{ __('Import bank statement (CSV)') }}</h3>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Bookings that were already imported are skipped automatically – you can import overlapping periods without duplicates.') }}</p>

        <form wire:submit="importStatement" class="mt-4 space-y-4">
            <div>
                <x-input-label for="finance-statement" :value="__('CSV file')" />
                <input id="finance-statement" type="file" wire:model="statement" accept=".csv,text/csv,text/plain"
                       class="mt-1 block w-full text-sm text-gray-700 file:me-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-indigo-700 dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
                <div wire:loading wire:target="statement" class="mt-1 text-xs text-gray-500">{{ __('Uploading …') }}</div>
                <x-input-error :messages="$errors->get('statement')" class="mt-1" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="finance-account" :value="__('Account')" />
                    <select id="finance-account" wire:model.live="importAccount" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        @if ($detected && $detected['accounts'] !== [])
                            <option value="auto">⇄ {{ __('Accounts from the file (:count)', ['count' => count($detected['accounts'])]) }}</option>
                        @endif
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                        <option value="new">＋ {{ __('New account') }}</option>
                    </select>
                </div>
                @if ($importAccount === 'new')
                    <div>
                        <x-input-label for="finance-account-name" :value="__('Account name')" />
                        <x-text-input id="finance-account-name" wire:model="newAccountName" class="mt-1 block w-full" placeholder="{{ __('e.g. Sparkasse checking') }}" />
                        <x-input-error :messages="$errors->get('newAccountName')" class="mt-1" />
                    </div>
                @endif
            </div>

            @if ($detected)
                <p class="rounded-xl bg-indigo-50 px-4 py-2.5 text-sm text-indigo-900 dark:bg-indigo-900/30 dark:text-indigo-100" role="status">
                    @if ($detected['format'] === 'outbank')
                        ✓ {{ __('Outbank export recognised – its categories are used.') }}
                    @else
                        ✓ {{ __('Bank statement recognised.') }}
                    @endif
                    @if ($detected['accounts'] !== [])
                        {{ __('Accounts in the file: :names', ['names' => implode(', ', array_slice($detected['accounts'], 0, 6)).(count($detected['accounts']) > 6 ? ' …' : '')]) }}
                    @endif
                </p>
            @endif

            <x-primary-button wire:loading.attr="disabled" wire:target="importStatement,statement">
                <span wire:loading.remove wire:target="importStatement">{{ __('Import') }}</span>
                <span wire:loading wire:target="importStatement">{{ __('Importing …') }}</span>
            </x-primary-button>
        </form>

        @if ($importResult)
            <div class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100" role="status">
                <p class="font-medium">✓ {{ trans_choice(':count booking imported|:count bookings imported', $importResult['imported']) }}</p>
                @if (! empty($importResult['accounts']))
                    <p>{{ __('Into the accounts: :names', ['names' => implode(', ', $importResult['accounts'])]) }}</p>
                @endif
                @if ($importResult['skipped'] > 0)
                    <p>{{ trans_choice(':count booking was already there|:count bookings were already there', $importResult['skipped']) }}</p>
                @endif
                @if ($importResult['errors'] > 0)
                    <p>{{ trans_choice(':count line could not be read|:count lines could not be read', $importResult['errors']) }}</p>
                @endif
                <button type="button" wire:click="$set('tab', 'report')" class="mt-1 font-medium underline">📊 {{ __('Show report') }}</button>
            </div>
        @endif
        @if ($importError)
            <p class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $importError }}</p>
        @endif
    </section>

    <div class="space-y-5 lg:col-span-2">
        <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('How do I get the CSV?') }}</h3>
            <ul class="mt-2 space-y-1.5 text-sm text-gray-600 dark:text-gray-400">
                <li><strong class="text-gray-800 dark:text-gray-200">Sparkasse / Volksbank:</strong> {{ __('Account → Transactions → Export → CSV (CAMT)') }}</li>
                <li><strong class="text-gray-800 dark:text-gray-200">ING:</strong> {{ __('Account → Transactions → Download CSV') }}</li>
                <li><strong class="text-gray-800 dark:text-gray-200">DKB:</strong> {{ __('Transactions → Download icon → CSV') }}</li>
                <li><strong class="text-gray-800 dark:text-gray-200">comdirect:</strong> {{ __('Transactions → Export as CSV') }}</li>
                <li><strong class="text-gray-800 dark:text-gray-200">N26:</strong> {{ __('Web app → Downloads → Account statement CSV') }}</li>
                <li><strong class="text-gray-800 dark:text-gray-200">Outbank:</strong> {{ __('Select accounts → Export → CSV. Several accounts in one file are split automatically, Outbank categories are kept.') }}</li>
            </ul>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Other banks work too as long as the file has columns for date, amount and payee.') }}</p>
        </section>

        @if ($accounts->isNotEmpty())
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Accounts') }}</h3>
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($accounts as $account)
                        <li wire:key="account-{{ $account->id }}" class="flex items-center gap-2 py-2" x-data="{ editing: false, name: @js($account->name) }">
                            <div class="min-w-0 flex-1" x-show="! editing">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">🏦 {{ $account->name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count booking|:count bookings', $account->transactions_count) }}</p>
                            </div>
                            <form x-show="editing" x-cloak class="flex min-w-0 flex-1 gap-2" x-on:submit.prevent="$wire.renameAccount({{ $account->id }}, name); editing = false">
                                <input type="text" x-model="name" maxlength="100" aria-label="{{ __('Account name') }}" class="min-w-0 flex-1 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                <button type="submit" class="text-sm text-indigo-600 dark:text-indigo-300">{{ __('Save') }}</button>
                            </form>
                            <button type="button" x-show="! editing" x-on:click="editing = true" class="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Rename') }}">✎</button>
                            <button type="button" x-show="! editing" wire:click="deleteAccount({{ $account->id }})" wire:confirm="{{ __('Delete this account with all its bookings?') }}" class="rounded p-1 text-gray-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-gray-700" aria-label="{{ __('Delete') }}">🗑</button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
