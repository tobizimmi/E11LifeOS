{{-- Balance forecast from the current balances, recurring bookings and average other spending. --}}
@php($date = fn (string $value) => \Illuminate\Support\Carbon::parse($value)->translatedFormat('D, j. M'))

@if (! $forecast)
    <section class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
        <p class="text-4xl" aria-hidden="true">🔮</p>
        <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('No account balance yet') }}</h3>
        <p class="mx-auto mt-1 max-w-lg text-sm text-gray-600 dark:text-gray-400">{{ __('The forecast starts from the current balance of your accounts. Fetch it via the bank sync or enter it once under net worth.') }}</p>
        <div class="mt-4 flex flex-wrap justify-center gap-2">
            <a href="{{ route('portfolio.banking') }}" wire:navigate class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">🏦 {{ __('Bank sync') }}</a>
            <a href="{{ route('net-worth.index') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">{{ __('Net worth') }}</a>
        </div>
    </section>
@else
    @if ($forecast['first_negative'])
        <div class="flex gap-3 rounded-2xl bg-red-50 p-4 text-sm text-red-800 ring-1 ring-red-200 dark:bg-red-900/30 dark:text-red-200 dark:ring-red-800" role="alert">
            <span aria-hidden="true">⚠️</span>
            <p>{{ __(':account is expected to go below zero on :date. Move money in time or postpone a payment.', ['account' => $forecast['first_negative']['account'], 'date' => $date($forecast['first_negative']['date'])]) }}</p>
        </div>
    @endif

    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">🔮 {{ __('Balance forecast') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Current balance plus recurring income and payments on their next dates, minus your average other spending of :amount per day.', ['amount' => $euro($forecast['variable_daily'])]) }}</p>
            </div>
            <div class="inline-flex rounded-lg bg-gray-100 p-1 dark:bg-gray-900" role="group" aria-label="{{ __('Forecast period') }}">
                @foreach (\App\Support\Finance\BalanceForecast::HORIZONS as $horizon)
                    <button type="button" wire:click="$set('forecastDays', {{ $horizon }})" aria-pressed="{{ $forecastDays === $horizon ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1 text-sm', 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' => $forecastDays === $horizon, 'text-gray-600 dark:text-gray-400' => $forecastDays !== $horizon])>{{ __(':days days', ['days' => $horizon]) }}</button>
                @endforeach
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Today') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($forecast['start']) }}</dd>
            </div>
            @foreach ($forecast['milestones'] as $horizon => $value)
                <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('In :days days', ['days' => $horizon]) }}</dt>
                    <dd @class(['mt-1 text-lg font-semibold tabular-nums', 'text-red-600 dark:text-red-400' => $value < 0, 'text-gray-900 dark:text-gray-100' => $value >= 0])>{{ $euro($value) }}</dd>
                </div>
            @endforeach
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Lowest point') }}</dt>
                <dd @class(['mt-1 text-lg font-semibold tabular-nums', 'text-red-600 dark:text-red-400' => $forecast['lowest']['value'] < 0, 'text-gray-900 dark:text-gray-100' => $forecast['lowest']['value'] >= 0])>{{ $euro($forecast['lowest']['value']) }}</dd>
                <dd class="text-xs text-gray-500 dark:text-gray-400">{{ $date($forecast['lowest']['date']) }}</dd>
            </div>
        </dl>

        <x-line-chart :points="$forecast['points']" :value-label="__('Expected balance')" class="mt-4" wire:key="forecast-chart-{{ $forecastDays }}" />
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">📅 {{ __('Expected bookings') }}</h3>
            @if ($forecast['events'] === [])
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No recurring bookings found yet. They are recognised after two to three months of bookings.') }}</p>
            @else
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach (array_slice($forecast['events'], 0, 40) as $event)
                        <li class="flex items-center gap-3 py-2 text-sm">
                            <span class="w-24 shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $date($event['date']) }}</span>
                            <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $event['name'] }}<span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $frequencies[$event['frequency']] ?? '' }} · {{ $event['account'] }}</span></span>
                            <span @class(['tabular-nums font-medium', 'text-emerald-700 dark:text-emerald-400' => $event['amount'] > 0, 'text-gray-900 dark:text-gray-100' => $event['amount'] <= 0])>{{ $event['amount'] > 0 ? '+' : '' }}{{ $euro($event['amount']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">🏦 {{ __('Per account') }}</h3>
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($forecast['accounts'] as $row)
                    <li class="py-2 text-sm">
                        <div class="flex items-center gap-3">
                            <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $row['account']->name }}</span>
                            <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $euro($row['start']) }} → <strong @class(['text-red-600 dark:text-red-400' => $row['end'] < 0])>{{ $euro($row['end']) }}</strong></span>
                        </div>
                        <p @class(['text-xs', 'text-red-600 dark:text-red-400' => $row['first_negative'], 'text-gray-500 dark:text-gray-400' => ! $row['first_negative']])>
                            {{ $row['first_negative'] ? __('Below zero from :date', ['date' => $date($row['first_negative'])]) : __('Lowest :amount on :date', ['amount' => $euro($row['lowest']['value']), 'date' => $date($row['lowest']['date'])]) }}
                        </p>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Accounts without a balance are left out. The balance is updated with every bank sync.') }}</p>
        </section>
    </div>
@endif
