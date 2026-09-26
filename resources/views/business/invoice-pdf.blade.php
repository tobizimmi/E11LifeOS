@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $isStorno = $invoice->isCreditNote() && $invoice->status === 'cancelled';
    $title = match ($invoice->type) {
        'quote' => $invoice->isDraft() ? __('Quote draft') : __('Sales quote'),
        'credit_note' => $isStorno ? __('Cancellation invoice') : ($invoice->isDraft() ? __('Credit note draft') : __('Credit note')),
        default => $invoice->isDraft() ? __('Invoice draft') : __('Invoice'),
    };
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $profile->accent_color) ? $profile->accent_color : '#4338ca';
    $hasDiscount = $invoice->items->contains(fn ($item) => $item->discount_percent > 0);
    $logo = $profile->logoDataUri();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $invoice->number }}</title>
    <style>
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.45; margin: 0; }
        .page { padding: 16mm 20mm 36mm 25mm; }
        .letterhead { position: absolute; top: 12mm; right: 20mm; text-align: right; font-size: 8.5pt; color: #4b5563; }
        .letterhead strong { display: block; font-size: 13pt; color: #111827; margin-bottom: 2pt; }
        .window { position: absolute; top: 45mm; left: 25mm; width: 85mm; }
        .sender { font-size: 7pt; color: #6b7280; border-bottom: 0.5pt solid #9ca3af; padding-bottom: 1pt; margin-bottom: 4pt; }
        .recipient { font-size: 10pt; white-space: pre-line; }
        .info { position: absolute; top: 60mm; right: 20mm; width: 65mm; font-size: 8.5pt; }
        .info td { padding: 1pt 0; }
        .info td.label { color: #6b7280; padding-right: 6pt; }
        .body { margin-top: 98mm; }
        h1 { font-size: 15pt; margin: 0 0 8pt; color: {{ $accent }}; }
        .logo { position: absolute; top: 12mm; left: 25mm; max-height: 20mm; max-width: 60mm; }
        table.items { width: 100%; border-collapse: collapse; margin: 10pt 0 4pt; }
        table.items th { text-align: left; font-size: 8pt; color: #4b5563; border-bottom: 1pt solid {{ $accent }}; padding: 4pt 4pt; }
        table.items td { padding: 5pt 4pt; border-bottom: 0.5pt solid #e5e7eb; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .desc { white-space: pre-line; }
        table.totals { width: 55%; margin-left: 45%; border-collapse: collapse; }
        table.totals td { padding: 2pt 4pt; }
        table.totals tr.grand td { border-top: 1pt solid {{ $accent }}; font-weight: bold; font-size: 11pt; padding-top: 4pt; }
        .note { margin-top: 10pt; }
        .muted { color: #6b7280; }
        .footer { position: fixed; bottom: 10mm; left: 25mm; right: 20mm; font-size: 7pt; color: #6b7280; border-top: 0.5pt solid #d1d5db; padding-top: 4pt; }
        .footer td { vertical-align: top; width: 33%; padding-right: 6pt; }
        .stamp { position: fixed; top: 120mm; left: 0; right: 0; text-align: center; font-size: 60pt; color: #e5e7eb; transform: rotate(-25deg); z-index: -1; font-weight: bold; }
    </style>
</head>
<body>
    @if ($invoice->isDraft())
        <div class="stamp">{{ mb_strtoupper(__('Draft')) }}</div>
    @elseif ($invoice->status === 'cancelled')
        <div class="stamp">{{ mb_strtoupper(__('Cancelled')) }}</div>
    @endif

    @if ($logo)
        <img src="{{ $logo }}" alt="" class="logo">
    @endif

    <div class="letterhead">
        <strong>{{ $profile->company_name }}</strong>
        @if ($profile->owner_name && $profile->owner_name !== $profile->company_name){{ $profile->owner_name }}<br>@endif
        {{ $profile->street }}<br>
        {{ trim($profile->postal_code.' '.$profile->city) }}<br>
        @if ($profile->phone){{ $profile->phone }}<br>@endif
        @if ($profile->email){{ $profile->email }}<br>@endif
        @if ($profile->website){{ $profile->website }}@endif
    </div>

    <div class="window">
        <div class="sender">{{ $profile->senderLine() }}</div>
        <div class="recipient">{{ $invoice->recipient }}</div>
    </div>

    <table class="info">
        <tr><td class="label">{{ match ($invoice->type) { 'quote' => __('Quote no.'), 'credit_note' => __('Credit note no.'), default => __('Invoice no.') } }}</td><td>{{ $invoice->number ?? '—' }}</td></tr>
        <tr><td class="label">{{ __('Date') }}</td><td>{{ ($invoice->issued_on ?? today())->format('d.m.Y') }}</td></tr>
        @if ($invoice->isCreditNote() && $invoice->related)
            <tr><td class="label">{{ __('For invoice') }}</td><td>{{ $invoice->related->number }} ({{ $invoice->related->issued_on?->format('d.m.Y') }})</td></tr>
        @endif
        @if ($invoice->isQuote() && $invoice->valid_until)
            <tr><td class="label">{{ __('Valid until') }}</td><td>{{ $invoice->valid_until->format('d.m.Y') }}</td></tr>
        @endif
        @if ($invoice->service_period)
            <tr><td class="label">{{ __('Service date') }}</td><td>{{ $invoice->service_period }}</td></tr>
        @endif
        @if ($invoice->customer_id)
            <tr><td class="label">{{ __('Customer no.') }}</td><td>{{ $invoice->customer_id }}</td></tr>
        @endif
        @if ($profile->tax_number)
            <tr><td class="label">{{ __('Tax number') }}</td><td>{{ $profile->tax_number }}</td></tr>
        @endif
        @if ($profile->vat_id)
            <tr><td class="label">{{ __('VAT ID') }}</td><td>{{ $profile->vat_id }}</td></tr>
        @endif
        @if ($invoice->customer?->vat_id)
            <tr><td class="label">{{ __('Your VAT ID') }}</td><td>{{ $invoice->customer->vat_id }}</td></tr>
        @endif
    </table>

    <div class="page">
        <div class="body">
            <h1>{{ $title }}{{ $invoice->number ? ' '.$invoice->number : '' }}@if ($invoice->subject) – {{ $invoice->subject }}@endif</h1>

            @if ($invoice->intro)
                <p>{!! nl2br(e($invoice->intro)) !!}</p>
            @endif

            <table class="items">
                <thead>
                    <tr>
                        <th style="width: 6%">{{ __('Pos.') }}</th>
                        <th>{{ __('Description') }}</th>
                        <th class="num" style="width: 14%">{{ __('Quantity') }}</th>
                        <th class="num" style="width: 15%">{{ __('Unit price') }}</th>
                        @if ($hasDiscount)
                            <th class="num" style="width: 9%">{{ __('Discount') }}</th>
                        @endif
                        @unless ($invoice->small_business)
                            <th class="num" style="width: 8%">{{ __('VAT') }}</th>
                        @endunless
                        <th class="num" style="width: 15%">{{ __('Total price') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td>{{ $item->position }}</td>
                            <td class="desc">{{ $item->description }}</td>
                            <td class="num">{{ $quantity($item->quantity) }} {{ $item->unit }}</td>
                            <td class="num">{{ $euro($item->unit_price) }}</td>
                            @if ($hasDiscount)
                                <td class="num">{{ $item->discount_percent > 0 ? rtrim(rtrim(number_format($item->discount_percent, 2, ',', ''), '0'), ',').' %' : '' }}</td>
                            @endif
                            @unless ($invoice->small_business)
                                <td class="num">{{ rtrim(rtrim(number_format($item->vat_rate, 2, ',', ''), '0'), ',') }} %</td>
                            @endunless
                            <td class="num">{{ $euro($item->lineTotal()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="totals">
                @unless ($invoice->small_business)
                    <tr><td>{{ __('Net amount') }}</td><td class="num">{{ $euro($invoice->net_total) }}</td></tr>
                    @foreach ($invoice->vatBreakdown() as $row)
                        <tr><td>{{ __('VAT :rate % on :net', ['rate' => rtrim(rtrim(number_format($row['rate'], 2, ',', ''), '0'), ','), 'net' => $euro($row['net'])]) }}</td><td class="num">{{ $euro($row['vat']) }}</td></tr>
                    @endforeach
                @endunless
                <tr class="grand"><td>{{ __('Total amount') }}</td><td class="num">{{ $euro($invoice->gross_total) }}</td></tr>
            </table>

            @if ($invoice->small_business)
                <p class="note">{{ __('According to § 19 UStG no VAT is charged.') }}</p>
            @endif

            @if ($invoice->type === 'invoice')
                @if ($invoice->status === 'paid')
                    <p class="note">{{ __('Paid on :date – thank you!', ['date' => $invoice->paid_on?->format('d.m.Y')]) }}</p>
                @elseif ($invoice->status === 'open' && abs($invoice->paid_total) > 0.004)
                    <p class="note">{{ __('Already paid: :paid. Please transfer the remaining :amount by :date, quoting the invoice number.', ['paid' => $euro($invoice->paid_total), 'amount' => $euro($invoice->outstanding()), 'date' => ($invoice->due_on ?? today())->format('d.m.Y')]) }}</p>
                @elseif ($invoice->status !== 'cancelled')
                    <p class="note">{{ __('Please transfer :amount by :date to the account below, quoting the invoice number.', ['amount' => $euro($invoice->gross_total), 'date' => ($invoice->due_on ?? today()->addDays($profile->payment_days))->format('d.m.Y')]) }}</p>
                @endif
            @elseif ($invoice->isQuote() && $invoice->valid_until)
                <p class="note">{{ __('This quote is valid until :date.', ['date' => $invoice->valid_until->format('d.m.Y')]) }}</p>
            @elseif ($isStorno && $invoice->related)
                <p class="note">{{ __('This cancellation invoice cancels invoice :number of :date in full.', ['number' => $invoice->related->number, 'date' => $invoice->related->issued_on?->format('d.m.Y')]) }}</p>
            @elseif ($invoice->isCreditNote())
                <p class="note">{{ __('The amount of :amount will be refunded to you.', ['amount' => $euro(abs($invoice->gross_total))]) }}</p>
            @endif

            @if ($invoice->closing)
                <p class="note">{!! nl2br(e($invoice->closing)) !!}</p>
            @endif
        </div>
    </div>

    <div class="footer">
        <table style="width: 100%">
            <tr>
                <td>{{ $profile->company_name }}@if ($profile->owner_name && $profile->owner_name !== $profile->company_name)<br>{{ __('Owner') }}: {{ $profile->owner_name }}@endif<br>{{ $profile->street }}<br>{{ trim($profile->postal_code.' '.$profile->city) }}</td>
                <td>@if ($profile->tax_number){{ __('Tax number') }}: {{ $profile->tax_number }}<br>@endif @if ($profile->vat_id){{ __('VAT ID') }}: {{ $profile->vat_id }}<br>@endif @if ($profile->email){{ $profile->email }}@endif</td>
                <td>@if ($profile->iban){{ $profile->bank_name }}<br>IBAN: {{ trim(chunk_split($profile->iban, 4, ' ')) }}<br>@if ($profile->bic)BIC: {{ $profile->bic }}@endif @endif</td>
            </tr>
        </table>
    </div>
</body>
</html>
