@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $group->title }}</title>
    <style>
        @page { margin: 16mm 16mm 16mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #111827; line-height: 1.4; }
        h1 { font-size: 16pt; margin: 0 0 1mm; }
        h2 { font-size: 11pt; margin: 6mm 0 2mm; padding-bottom: 1mm; border-bottom: 0.6pt solid #9ca3af; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8pt; color: #6b7280; border-bottom: 0.6pt solid #9ca3af; padding: 1mm 1mm 1mm 0; }
        td { padding: 1mm 1mm 1mm 0; vertical-align: top; border-bottom: 0.3pt solid #e5e7eb; }
        .right { text-align: right; }
        .plus { color: #047857; } .minus { color: #b91c1c; }
    </style>
</head>
<body>
    <h1>{{ $group->title }}</h1>
    <p class="muted">{{ __('As of :date', ['date' => today()->format('d.m.Y')]) }} · {{ __(':total spent · :amount per person on average', ['total' => $euro($stats['total']), 'amount' => $euro($stats['per_person'])]) }}</p>

    <h2>{{ __('How to settle up') }}</h2>
    @forelse ($settlements as $transfer)
        <p>{{ $names[$transfer['from']] }} → {{ $names[$transfer['to']] }}: <strong>{{ $euro($transfer['amount']) }}</strong></p>
    @empty
        <p>{{ __('Everything is settled.') }}</p>
    @endforelse

    <h2>{{ __('Balances') }}</h2>
    <table>
        <tr><th>{{ __('Name') }}</th><th class="right">{{ __('paid') }}</th><th class="right">{{ __('share') }}</th><th class="right">{{ __('Balance') }}</th></tr>
        @foreach ($balances as $row)
            <tr><td>{{ $row['member']->name }}</td><td class="right">{{ $euro($row['paid']) }}</td><td class="right">{{ $euro($row['owed']) }}</td><td class="right {{ $row['balance'] > 0.004 ? 'plus' : ($row['balance'] < -0.004 ? 'minus' : '') }}">{{ $euro($row['balance']) }}</td></tr>
        @endforeach
    </table>

    <h2>{{ __('By category') }}</h2>
    <table>
        @foreach ($stats['by_category'] as $key => $sum)
            <tr><td>{{ $categories[$key]['label'] ?? $key }}</td><td class="right">{{ $euro($sum) }}</td><td class="right muted">{{ $stats['total'] > 0 ? round($sum / $stats['total'] * 100) : 0 }} %</td></tr>
        @endforeach
    </table>

    <h2>{{ __('Expenses') }}</h2>
    <table>
        <tr><th>{{ __('Date') }}</th><th>{{ __('Title') }}</th><th>{{ __('Paid by') }}</th><th>{{ __('For whom?') }}</th><th class="right">{{ __('Amount') }}</th></tr>
        @foreach ($group->expenses->sortBy('spent_on') as $item)
            <tr>
                <td>{{ $item->spent_on->format('d.m.Y') }}</td>
                <td>{{ $item->title }}{{ $item->is_transfer ? ' ('.__('Settlement payment').')' : '' }}</td>
                <td>{{ $item->payer?->name }}</td>
                <td class="muted">{{ $item->shares->map(fn ($share) => ($names[$share->expense_member_id] ?? '?').' '.$euro($share->amount))->implode(', ') }}</td>
                <td class="right">{{ $euro($item->amount) }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
