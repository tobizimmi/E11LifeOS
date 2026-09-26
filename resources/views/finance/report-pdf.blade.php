@php
    $euro = fn ($value, $decimals = 2) => number_format((float) $value, $decimals, ',', '.').' €';
    $frequencies = ['weekly' => __('weekly'), 'monthly' => __('monthly'), 'bimonthly' => __('every 2 months'), 'quarterly' => __('quarterly'), 'halfyearly' => __('half-yearly'), 'yearly' => __('yearly')];
    $maxMonth = max(1, collect($report['monthly'])->flatMap(fn ($month) => [$month['income'], $month['expenses']])->max() ?? 1);
    $maxCategory = max(1, collect($report['categories'])->max('amount') ?? 1);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Finance report') }}</title>
    <style>
        @page { margin: 16mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.4; }
        h1 { font-size: 18pt; margin: 0 0 2pt; }
        h2 { font-size: 11.5pt; margin: 14pt 0 6pt; color: #4338ca; border-bottom: 1px solid #e5e7eb; padding-bottom: 3pt; }
        .meta { color: #6b7280; font-size: 9pt; margin-bottom: 10pt; }
        table { width: 100%; border-collapse: collapse; }
        .kpis td { width: 25%; padding: 6pt 8pt; background: #f9fafb; border: 3pt solid #fff; vertical-align: top; }
        .kpi-label { font-size: 8pt; color: #6b7280; }
        .kpi-value { font-size: 14pt; font-weight: bold; }
        .rows td { padding: 3pt 4pt; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #6b7280; font-size: 8pt; }
        .bar { height: 7pt; border-radius: 3pt; }
        .income { background: #2a78d6; }
        .expense { background: #eb6834; }
        .dot { display: inline-block; width: 7pt; height: 7pt; border-radius: 2pt; margin-right: 3pt; }
        .tip { padding: 5pt 7pt; margin-bottom: 5pt; background: #f0fdf4; border-left: 3pt solid #1baf7a; }
        .footer { margin-top: 16pt; font-size: 7.5pt; color: #9ca3af; }
    </style>
</head>
<body>
    <h1>{{ __('Finance report') }}</h1>
    <div class="meta">{{ $periodLabel }} · {{ $report['from']->format('d.m.Y') }} – {{ $report['to']->format('d.m.Y') }} · {{ $account?->name ?? __('All accounts') }} · {{ trans_choice(':count booking|:count bookings', $report['count']) }}</div>

    @if ($report['count'] === 0)
        <p>{{ __('No bookings in this period.') }}</p>
    @else
        <table class="kpis">
            <tr>
                <td><div class="kpi-label">{{ __('Income') }}</div><div class="kpi-value">{{ $euro($report['income'], 0) }}</div><div class="muted">{{ __('Ø :amount per month', ['amount' => $euro($report['avgIncome'], 0)]) }}</div></td>
                <td><div class="kpi-label">{{ __('Expenses') }}</div><div class="kpi-value">{{ $euro($report['expenses'], 0) }}</div><div class="muted">{{ __('Ø :amount per month', ['amount' => $euro($report['avgExpenses'], 0)]) }}</div></td>
                <td><div class="kpi-label">{{ __('Left over') }}</div><div class="kpi-value">{{ $euro($report['balance'], 0) }}</div></td>
                <td><div class="kpi-label">{{ __('Savings rate') }}</div><div class="kpi-value">{{ $report['savingsRate'] !== null ? number_format($report['savingsRate'], 1, ',', '.').' %' : '—' }}</div></td>
            </tr>
        </table>

        <h2>{{ __('Income and expenses per month') }}</h2>
        <p class="muted"><span class="dot income"></span>{{ __('Income') }} &nbsp; <span class="dot expense"></span>{{ __('Expenses') }}</p>
        <table class="rows">
            @foreach ($report['monthly'] as $month)
                <tr>
                    <td style="width: 16%">{{ $month['label'] }}</td>
                    <td style="width: 56%">
                        <div class="bar income" style="width: {{ max(0.5, $month['income'] / $maxMonth * 100) }}%"></div>
                        <div class="bar expense" style="width: {{ max(0.5, $month['expenses'] / $maxMonth * 100) }}%; margin-top: 2pt"></div>
                    </td>
                    <td class="num" style="width: 14%">{{ $euro($month['income'], 0) }}</td>
                    <td class="num" style="width: 14%">{{ $euro($month['expenses'], 0) }}</td>
                </tr>
            @endforeach
        </table>

        <h2>{{ __('Expenses by category') }}</h2>
        <table class="rows">
            @foreach ($report['categories'] as $category)
                <tr>
                    <td style="width: 28%">{{ $category['label'] }}</td>
                    <td style="width: 36%"><div class="bar expense" style="width: {{ max(0.5, $category['amount'] / $maxCategory * 100) }}%"></div></td>
                    <td class="num" style="width: 10%">{{ number_format($category['share'], 1, ',', '.') }} %</td>
                    <td class="num" style="width: 13%">{{ $euro($category['perMonth'], 0) }} / {{ __('month') }}</td>
                    <td class="num" style="width: 13%">{{ $euro($category['amount'], 0) }}</td>
                </tr>
            @endforeach
        </table>

        @if ($report['split']['needs'] !== null)
            <h2>{{ __('50/30/20 check') }}</h2>
            <table class="rows">
                @foreach (['needs' => [__('Needs'), 50], 'wants' => [__('Wishes & lifestyle'), 30], 'savings' => [__('Left for saving'), 20]] as $key => [$label, $target])
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="num">{{ number_format($report['split'][$key], 1, ',', '.') }} %</td>
                        <td class="num muted">{{ __('Guideline :target %', ['target' => $target]) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($report['suggestions'] !== [])
            <h2>{{ __('Savings potential') }} @if ($report['potential'] > 0)· {{ __('about :amount per year', ['amount' => $euro($report['potential'], 0)]) }}@endif</h2>
            @foreach ($report['suggestions'] as $suggestion)
                <div class="tip">
                    <strong>{{ $suggestion['title'] }}</strong>@if ($suggestion['yearly'] > 0) · ~{{ $euro($suggestion['yearly'], 0) }} / {{ __('year') }}@endif<br>
                    {{ $suggestion['text'] }}
                </div>
            @endforeach
        @endif

        @if ($report['recurring'] !== [])
            <h2>{{ __('Recurring payments') }} · {{ $euro($report['recurringMonthly']) }} / {{ __('month') }}</h2>
            <table class="rows">
                @foreach ($report['recurring'] as $row)
                    <tr>
                        <td>{{ $row['name'] }}<br><span class="muted">{{ \App\Support\Finance\FinanceCategories::label($row['category']) }} · {{ $frequencies[$row['frequency']] ?? $row['frequency'] }}@if ($row['increase']) · +{{ $euro($row['increase']) }}@endif</span></td>
                        <td class="num">{{ $euro($row['amount']) }}</td>
                        <td class="num">{{ $euro($row['yearly'], 0) }} / {{ __('year') }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($report['payees'] !== [])
            <h2>{{ __('Where most of the money goes') }}</h2>
            <table class="rows">
                @foreach ($report['payees'] as $payee)
                    <tr>
                        <td>{{ $payee['name'] }}</td>
                        <td class="num muted">{{ trans_choice(':count booking|:count bookings', $payee['count']) }}</td>
                        <td class="num">{{ $euro($payee['amount'], 0) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endif

    <div class="footer">{{ __('Created with :app on :date. The evaluation is based on your imported bookings and is not financial advice.', ['app' => config('app.name'), 'date' => now()->format('d.m.Y')]) }}</div>
</body>
</html>
