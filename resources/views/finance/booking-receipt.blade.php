<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 10.5pt; color: #1d1d1f; margin: 18mm 16mm; }
        h1 { font-size: 16pt; margin: 0 0 4mm; }
        p.sub { color: #6e6e73; margin: 0 0 8mm; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; width: 34%; color: #6e6e73; font-weight: normal; padding: 2.2mm 0; border-bottom: 0.3pt solid #d2d2d7; vertical-align: top; }
        td { padding: 2.2mm 0; border-bottom: 0.3pt solid #d2d2d7; }
        .amount { font-size: 14pt; font-weight: bold; }
    </style>
</head>
<body>
    <h1 class="accent">{{ __('Booking receipt') }}</h1>
    <p class="sub">{{ __('Created from the bank booking in E11LifeOS.') }}</p>
    <table>
        <tr><th>{{ __('Date') }}</th><td>{{ $transaction->booked_on->format('d.m.Y') }}</td></tr>
        <tr><th>{{ __('Amount') }}</th><td class="amount">{{ number_format($transaction->amount, 2, ',', '.') }} €</td></tr>
        <tr><th>{{ $transaction->isExpense() ? __('Payee') : __('Payer') }}</th><td>{{ $transaction->counterparty ?: '—' }}</td></tr>
        <tr><th>{{ __('Purpose') }}</th><td>{{ $transaction->purpose ?: '—' }}</td></tr>
        @if ($category)
            <tr><th>{{ __('Category') }}</th><td>{{ $category['label'] }}</td></tr>
        @endif
        @if ($transaction->account)
            <tr><th>{{ __('Account') }}</th><td>{{ $transaction->account->name }}</td></tr>
        @endif
    </table>
</body>
</html>
