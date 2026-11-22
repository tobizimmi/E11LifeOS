{{-- Accounts overview: balance per account, depot values, and the totals (variables from finance/index). --}}
<div class="space-y-5">
    {{-- Totals --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">🏦 {{ __('Total on accounts') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($accountsTotal, 2) }}</p>
            @if ($accountsMissing > 0)
                <p class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">{{ trans_choice(':count account without a balance|:count accounts without a balance', $accountsMissing) }}</p>
            @endif
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">📈 {{ __('Total in depots') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($depotsTotal, 2) }}</p>
            @if ($depotRows->isEmpty())
                <p class="mt-0.5 text-xs text-gray-400">{{ __('No depot yet') }}</p>
            @endif
        </div>
        <div class="rounded-2xl bg-indigo-600 p-4 text-white shadow-xs">
            <p class="text-xs font-medium text-indigo-100">💰 {{ __('Accounts + depots') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $euro($accountsTotal + $depotsTotal, 2) }}</p>
            <a href="{{ route('net-worth.index') }}" wire:navigate class="mt-0.5 inline-block text-xs text-indigo-100 underline">{{ __('Full net worth (incl. property) →') }}</a>
        </div>
    </div>

    {{-- Accounts --}}
    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
        <h3 class="font-semibold text-gray-900 dark:text-gray-100">🏦 {{ __('Your accounts') }}</h3>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Rename an account, set its balance (bank-synced accounts update automatically) or open its bookings.') }}</p>

        <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($accountRows as $row)
                @php($account = $row['account'])
                <li wire:key="acct-{{ $account->id }}" class="flex flex-wrap items-center gap-3 py-3">
                    <input type="text" value="{{ $account->name }}" x-on:change="$wire.renameAccount({{ $account->id }}, $event.target.value)"
                        class="min-w-40 flex-1 rounded-lg border-transparent bg-transparent px-2 py-1 text-sm font-medium text-gray-900 hover:border-gray-300 focus:border-indigo-400 focus:bg-white dark:text-gray-100 dark:hover:border-gray-600 dark:focus:bg-gray-900"
                        aria-label="{{ __('Account name') }}" />

                    <span class="text-xs text-gray-400">{{ trans_choice(':count booking|:count bookings', $row['bookings']) }}</span>

                    <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Balance') }}
                        <input type="text" inputmode="decimal" value="{{ $row['balance'] !== null ? number_format($row['balance'], 2, ',', '') : '' }}"
                            x-on:change="$wire.setBalance({{ $account->id }}, $event.target.value)" placeholder="–"
                            class="w-28 rounded-md border-gray-300 py-1 text-end text-sm tabular-nums dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                            aria-label="{{ __('Balance of :account', ['account' => $account->name]) }}" /> €
                    </label>

                    <button type="button" wire:click="$set('accountFilter', '{{ $account->id }}'); $set('tab', 'transactions')"
                        class="rounded-lg px-2.5 py-1 text-xs text-indigo-600 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">{{ __('Bookings') }}</button>

                    <button type="button" wire:click="deleteAccount({{ $account->id }})"
                        wire:confirm="{{ __('Delete this account and all its bookings? This cannot be undone.') }}"
                        class="rounded-lg px-2 py-1 text-xs text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-gray-700" aria-label="{{ __('Delete') }}">🗑</button>
                </li>
            @empty
                <li class="py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No accounts yet – import a statement or connect a bank.') }}</li>
            @endforelse
        </ul>
    </section>

    {{-- Depots --}}
    @if ($depotRows->isNotEmpty())
        <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">📈 {{ __('Your depots') }}</h3>
            <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach ($depotRows as $row)
                    <li wire:key="depot-{{ $row['portfolio']->id }}" class="flex items-center justify-between gap-3 py-2.5">
                        <a href="{{ route('portfolio.index') }}" wire:navigate class="truncate text-gray-800 hover:underline dark:text-gray-100">{{ $row['portfolio']->name }}</a>
                        <span class="shrink-0 text-right tabular-nums text-gray-900 dark:text-gray-100">
                            {{ $row['value'] !== null ? $euro($row['value'], 2) : '—' }}
                            @if ($row['date'])<span class="block text-xs font-normal text-gray-400">{{ \Illuminate\Support\Carbon::parse($row['date'])->translatedFormat('j. M Y') }}</span>@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
