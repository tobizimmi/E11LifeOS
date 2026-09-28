@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $address = trim(implode(', ', array_filter([$realEstate->street, trim($realEstate->postal_code.' '.$realEstate->city)])));
    $total = collect($months)->sum('open');
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Zahlungserinnerung Miete – {{ $tenant->name }}</title>
    <style>@include('real-estate.pdf.styles')</style>
</head>
<body>
    <p class="small muted">{{ $realEstate->landlord_name }}@if ($realEstate->landlord_address) · {{ $realEstate->landlord_address }}@endif</p>
    <p style="margin: 18pt 0;">{{ $tenant->name }}<br>{{ $tenant->unit ? $tenant->unit.', ' : '' }}{{ $address ?: $realEstate->name }}</p>
    <p style="text-align: right;">{{ today()->format('d.m.Y') }}</p>

    <h1>{{ $level > 1 ? 'Mahnung' : 'Zahlungserinnerung' }}: offene Miete</h1>
    <p>Sehr geehrte Damen und Herren,</p>
    <p>für die Wohnung {{ $tenant->unit ? $tenant->unit.', ' : '' }}{{ $address ?: $realEstate->name }} sind folgende Mietzahlungen noch nicht (vollständig) bei uns eingegangen:</p>

    <table class="grid" style="margin: 8pt 0;">
        <thead><tr><th>Monat</th><th class="num">Soll</th><th class="num">Gezahlt</th><th class="num">Offen</th></tr></thead>
        <tbody>
            @foreach ($months as $month)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::create($year, $month['month'], 1)->translatedFormat('F Y') }}</td>
                    <td class="num">{{ $euro($month['expected']) }}</td>
                    <td class="num">{{ $euro($month['paid']) }}</td>
                    <td class="num">{{ $euro($month['open']) }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="3">Offener Betrag</td><td class="num">{{ $euro($total) }}</td></tr>
        </tbody>
    </table>

    <p>Bitte überweisen Sie den offenen Betrag von <strong>{{ $euro($total) }}</strong> bis spätestens <strong>{{ $deadline->format('d.m.Y') }}</strong>@if ($realEstate->landlord_iban) auf das Konto IBAN {{ $realEstate->landlord_iban }}@endif@if ($tenant->payment_reference) mit dem Verwendungszweck „{{ $tenant->payment_reference }}“@endif.</p>
    @if ($level > 1)
        <p>Wir weisen darauf hin, dass ein Zahlungsrückstand in Höhe von zwei Monatsmieten nach § 543 Abs. 2 Nr. 3 BGB zur fristlosen Kündigung berechtigen kann.</p>
    @else
        <p>Sollte sich Ihre Zahlung mit diesem Schreiben überschnitten haben, betrachten Sie es bitte als gegenstandslos.</p>
    @endif
    <p>Mit freundlichen Grüßen</p>

    <table class="signature"><tr><td><div class="line">{{ $realEstate->landlord_name ?: 'Vermieter' }}</div></td><td></td></tr></table>
</body>
</html>
