@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $group->icon }} {{ $group->title }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <main class="mx-auto max-w-2xl space-y-5 px-4 py-8">
            <header>
                <h1 class="text-2xl font-semibold">{{ $group->icon }} {{ $group->title }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __(':total spent · :amount per person on average', ['total' => $euro($stats['total']), 'amount' => $euro($stats['per_person'])]) }}</p>
            </header>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h2 class="font-semibold">{{ __('How to settle up') }}</h2>
                @forelse ($settlements as $transfer)
                    <p class="mt-2 flex justify-between gap-3 text-sm"><span><strong>{{ $names[$transfer['from']] }}</strong> → <strong>{{ $names[$transfer['to']] }}</strong></span><span class="font-semibold tabular-nums">{{ $euro($transfer['amount']) }}</span></p>
                @empty
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">✓ {{ __('Everything is settled.') }}</p>
                @endforelse
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h2 class="font-semibold">{{ __('Balances') }}</h2>
                @foreach ($balances as $row)
                    <p class="mt-2 flex justify-between gap-3 text-sm"><span>{{ $row['member']->name }} <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('paid :paid · share :share', ['paid' => $euro($row['paid']), 'share' => $euro($row['owed'])]) }}</span></span><span @class(['font-semibold tabular-nums', 'text-emerald-600' => $row['balance'] > 0.004, 'text-red-600' => $row['balance'] < -0.004])>{{ ($row['balance'] > 0 ? '+' : '').$euro($row['balance']) }}</span></p>
                @endforeach
            </section>

            <section class="rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <h2 class="px-5 pt-5 font-semibold">{{ __('Expenses') }}</h2>
                <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($group->expenses as $item)
                        <li class="flex items-center gap-3 px-5 py-3 text-sm">
                            <span aria-hidden="true">{{ $item->is_transfer ? '💸' : ($categories[$item->category]['icon'] ?? '🧾') }}</span>
                            <span class="min-w-0 flex-1"><span class="block truncate">{{ $item->title }}</span><span class="block text-xs text-gray-500 dark:text-gray-400">{{ $item->spent_on->format('d.m.Y') }} · {{ __('paid by :name', ['name' => $item->payer?->name]) }}</span></span>
                            <span class="font-semibold tabular-nums">{{ $euro($item->amount) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No expenses yet.') }}</li>
                    @endforelse
                </ul>
            </section>
            <p class="text-center text-xs text-gray-400">{{ __('Read-only view, shared from :app.', ['app' => config('app.name')]) }}</p>
        </main>
    </body>
</html>
