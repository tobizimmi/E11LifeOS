@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $profile->accent_color) ? $profile->accent_color : '#4338ca';
    $logo = $profile->logoDataUri();
    $title = match ($level) {
        1 => __('Payment reminder'),
        2 => __('First dunning letter'),
        default => __('Second dunning letter'),
    };
    $total = round($invoice->outstanding() + $fee, 2);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $invoice->number }}</title>
    <style>
        @include('business.pdf.styles')
        table.amounts { width: 60%; margin: 10pt 0 0 40%; border-collapse: collapse; }
        table.amounts td { padding: 3pt 4pt; border-bottom: 0.5pt solid #e5e7eb; }
        table.amounts tr.grand td { border-top: 1pt solid {{ $accent }}; border-bottom: none; font-weight: bold; font-size: 11pt; }
    </style>
</head>
<body>
    @include('business.pdf.letterhead', ['recipient' => $invoice->recipient])

    <table class="info">
        <tr><td class="label">{{ __('Date') }}</td><td>{{ today()->format('d.m.Y') }}</td></tr>
        <tr><td class="label">{{ __('Invoice no.') }}</td><td>{{ $invoice->number }}</td></tr>
        <tr><td class="label">{{ __('Invoice date') }}</td><td>{{ $invoice->issued_on?->format('d.m.Y') }}</td></tr>
        <tr><td class="label">{{ __('Due date') }}</td><td>{{ $invoice->due_on?->format('d.m.Y') }}</td></tr>
        @if ($invoice->customer_id)
            <tr><td class="label">{{ __('Customer no.') }}</td><td>{{ $invoice->customer_id }}</td></tr>
        @endif
    </table>

    <div class="page">
        <div class="body">
            <h1>{{ $title }} – {{ __('Invoice :number', ['number' => $invoice->number]) }}</h1>

            <p>{{ __('Dear Sir or Madam,') }}</p>
            <p>
                @if ($level === 1)
                    {{ __('in the hustle and bustle of everyday life, the payment of the following invoice may have been overlooked. We kindly ask you to transfer the open amount by :date.', ['date' => $deadline->format('d.m.Y')]) }}
                @elseif ($level === 2)
                    {{ __('unfortunately we have not yet received payment for the following invoice despite our reminder. Please transfer the open amount by :date at the latest.', ['date' => $deadline->format('d.m.Y')]) }}
                @else
                    {{ __('despite our reminder and first dunning letter, the following invoice is still unpaid. We ask you to transfer the open amount by :date at the latest. After that we reserve the right to take further steps.', ['date' => $deadline->format('d.m.Y')]) }}
                @endif
            </p>

            <table class="amounts">
                <tr><td>{{ __('Invoice amount') }}</td><td class="num">{{ $euro($invoice->gross_total) }}</td></tr>
                @if (abs($invoice->paid_total) > 0.004)
                    <tr><td>{{ __('Already paid') }}</td><td class="num">− {{ $euro($invoice->paid_total) }}</td></tr>
                @endif
                @if ($fee > 0)
                    <tr><td>{{ __('Dunning fee') }}</td><td class="num">{{ $euro($fee) }}</td></tr>
                @endif
                <tr class="grand"><td>{{ __('Amount due') }}</td><td class="num">{{ $euro($total) }}</td></tr>
            </table>

            <p class="note">{{ __('Please transfer the amount to the account below, quoting the invoice number :number.', ['number' => $invoice->number]) }}</p>
            <p class="note">{{ __('If you have already paid in the meantime, please disregard this letter.') }}</p>
            <p class="note">{{ __('Kind regards') }}<br>{{ $profile->owner_name ?: $profile->company_name }}</p>
        </div>
    </div>

    @include('business.pdf.footer')
</body>
</html>
