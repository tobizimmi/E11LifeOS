@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Profit calculation (EÜR) :year', ['year' => $report['year']]) }}</title>
    <style>
        @page { margin: 16mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2937; line-height: 1.4; }
        h1 { font-size: 16pt; margin: 0 0 2pt; }
        h2 { font-size: 11pt; margin: 14pt 0 5pt; color: #4338ca; border-bottom: 1px solid #e5e7eb; padding-bottom: 3pt; }
        .meta { color: #6b7280; margin-bottom: 8pt; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 3pt 4pt; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        th { text-align: left; font-size: 8pt; color: #4b5563; border-bottom: 1px solid #9ca3af; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        .result td { font-weight: bold; font-size: 11pt; background: #f3f4f6; }
        .small { font-size: 7.5pt; }
        .muted { color: #6b7280; }
        .hint { padding: 4pt 6pt; background: #fffbeb; border-left: 3pt solid #f59e0b; margin: 4pt 0; }
        .footer { margin-top: 14pt; font-size: 7.5pt; color: #9ca3af; }
    </style>
</head>
<body>
    <h1>{{ __('Profit calculation (EÜR) :year', ['year' => $report['year']]) }}</h1>
    <div class="meta">
        {{ $profile?->company_name }}@if ($profile?->owner_name) · {{ $profile->owner_name }}@endif
        @if ($profile?->tax_number) · {{ __('Tax number') }} {{ $profile->tax_number }}@endif
        · {{ $report['smallBusiness'] ? __('Small business (§ 19 UStG)') : __('Subject to VAT') }}
        · {{ __('Period') }} 01.01.{{ $report['year'] }} – 31.12.{{ $report['year'] }}
    </div>

    @foreach (['income' => __('Business income'), 'expense' => __('Business expenses')] as $section => $sectionLabel)
        <h2>{{ $sectionLabel }}</h2>
        <table>
            @foreach ($lineDefinitions as $key => $line)
                @if ($line['section'] === $section && $report['lines'][$key] != 0)
                    <tr><td>{{ $line['label'] }}</td><td class="num">{{ $euro($report['lines'][$key]) }}</td></tr>
                @endif
            @endforeach
            <tr class="total"><td>{{ __('Total') }}</td><td class="num">{{ $euro($section === 'income' ? $report['incomeTotal'] : $report['expenseTotal']) }}</td></tr>
        </table>
    @endforeach

    <table style="margin-top: 10pt">
        <tr class="result"><td>{{ $report['profit'] >= 0 ? __('Profit') : __('Loss') }}</td><td class="num">{{ $euro($report['profit']) }}</td></tr>
    </table>

    @if ($report['nonDeductible'] > 0)
        <p class="small muted">{{ __('Non-deductible share of business meals (30 %): :amount', ['amount' => $euro($report['nonDeductible'])]) }}</p>
    @endif
    @if ($report['limitStatus'] === 'next_year' || $report['limitStatus'] === 'exceeded')
        <div class="hint">{{ __('Revenue :amount – above 25,000 €: in the following year the small business rule no longer applies.', ['amount' => $euro($report['revenue'])]) }}</div>
    @endif
    @if ($report['openInvoiceCount'] > 0)
        <div class="hint">{{ trans_choice(':count invoice (:amount) is still open – it counts in the year it is paid.|:count invoices (:amount) are still open – they count in the year they are paid.', $report['openInvoiceCount'], ['amount' => $euro($report['openInvoiceTotal'])]) }}</div>
    @endif

    @unless ($report['smallBusiness'])
        <h2>{{ __('VAT overview') }}</h2>
        <table>
            <tr><td>{{ __('VAT received') }}</td><td class="num">{{ $euro($report['vat']['received']) }}</td></tr>
            <tr><td>{{ __('Input VAT paid') }}</td><td class="num">{{ $euro($report['vat']['paid']) }}</td></tr>
            <tr><td>{{ __('Already paid to the tax office') }}</td><td class="num">{{ $euro($report['vat']['payments']) }}</td></tr>
            <tr><td>{{ __('Refunded by the tax office') }}</td><td class="num">{{ $euro($report['vat']['refunded']) }}</td></tr>
            <tr class="total"><td>{{ $report['vat']['balance'] >= 0 ? __('Still to pay') : __('Refund expected') }}</td><td class="num">{{ $euro(abs($report['vat']['balance'])) }}</td></tr>
        </table>
    @endunless

    <h2>{{ __('Months') }}</h2>
    <table>
        <tr><th>{{ __('Month') }}</th><th class="num">{{ __('Income') }}</th><th class="num">{{ __('Expenses') }}</th></tr>
        @foreach ($report['monthly'] as $month)
            <tr><td>{{ $month['label'] }}</td><td class="num">{{ $euro($month['income']) }}</td><td class="num">{{ $euro($month['expenses']) }}</td></tr>
        @endforeach
    </table>

    <h2>{{ __('All bookings') }}</h2>
    <table class="small">
        <tr><th>{{ __('Date') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Description') }}</th><th>{{ __('Category') }}</th><th class="num">{{ __('Net') }}</th><th class="num">{{ __('VAT') }}</th><th class="num">{{ __('Gross') }}</th></tr>
        @forelse ($report['rows'] as $row)
            <tr>
                <td>{{ $row['date']->format('d.m.Y') }}</td>
                <td>{{ $row['reference'] }}@unless ($row['receipt']) <span class="muted">({{ __('no receipt') }})</span>@endunless</td>
                <td>{{ $row['description'] }}@if ($row['counterparty'])<br><span class="muted">{{ $row['counterparty'] }}</span>@endif</td>
                <td>{{ $row['category'] }}</td>
                <td class="num">{{ $row['kind'] === 'expense' ? '−' : '' }}{{ $euro($row['net']) }}</td>
                <td class="num">{{ $euro($row['vat']) }}</td>
                <td class="num">{{ $row['kind'] === 'expense' ? '−' : '' }}{{ $euro($row['gross']) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">{{ __('No bookings in this year.') }}</td></tr>
        @endforelse
    </table>

    <div class="footer">{{ __('Created with :app on :date. Cash accounting (Zuflussprinzip). This evaluation helps with the tax return and is not tax advice.', ['app' => config('app.name'), 'date' => now()->format('d.m.Y')]) }}</div>
</body>
</html>
