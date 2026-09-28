@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $address = trim(implode(', ', array_filter([$realEstate->street, trim($realEstate->postal_code.' '.$realEstate->city)])));
    $tenant = $row['tenant'];
    $keys = \App\Models\RealEstateUtilityStatement::keyLabels();
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Betriebskostenabrechnung {{ $statement->year }} – {{ $tenant->name }}</title>
    <style>@include('real-estate.pdf.styles')</style>
</head>
<body>
    <p class="small muted">{{ $realEstate->landlord_name }}@if ($realEstate->landlord_address) · {{ $realEstate->landlord_address }}@endif</p>
    <p style="margin: 18pt 0;">{{ $tenant->name }}<br>{{ $tenant->unit ? $tenant->unit.', ' : '' }}{{ $address ?: $realEstate->name }}</p>
    <p style="text-align: right;">{{ today()->format('d.m.Y') }}</p>

    <h1>Betriebskostenabrechnung {{ $statement->year }}</h1>
    <p>Abrechnungszeitraum {{ $statement->period_start->format('d.m.Y') }} – {{ $statement->period_end->format('d.m.Y') }} ({{ $periodDays }} Tage) ·
        Ihre Nutzungszeit: {{ $row['days'] }} Tage · {{ $tenant->persons }} {{ $tenant->persons === 1 ? 'Person' : 'Personen' }}@if ($tenant->area) · {{ number_format($tenant->area, 2, ',', '.') }} m²@endif</p>

    <table class="grid" style="margin-top: 8pt;">
        <thead>
            <tr>
                <th>Kostenart</th>
                <th>Umlageschlüssel</th>
                <th class="num">Gesamtkosten</th>
                <th class="num">Ihr Anteil</th>
                <th class="num">Ihre Kosten</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($row['lines'] as $line)
                <tr>
                    <td>{{ $line['label'] }}</td>
                    <td>{{ $keys[$line['key']] ?? $line['key'] }}</td>
                    <td class="num">{{ $euro($line['total']) }}</td>
                    <td class="num">{{ number_format($line['share'], 2, ',', '.') }} %</td>
                    <td class="num">{{ $euro($line['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="4">Ihre Betriebskosten</td><td class="num">{{ $euro($row['costs']) }}</td></tr>
            <tr><td colspan="4">abzüglich Ihrer Vorauszahlungen</td><td class="num">− {{ $euro($row['advances']) }}</td></tr>
            <tr class="total">
                <td colspan="4">{{ $row['balance'] > 0 ? 'Nachzahlung' : 'Guthaben' }}</td>
                <td class="num">{{ $euro(abs($row['balance'])) }}</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 12pt;">
        @if ($row['balance'] > 0)
            Bitte überweisen Sie die Nachzahlung innerhalb von 30 Tagen nach Zugang dieser Abrechnung{{ $realEstate->landlord_iban ? ' auf das Konto IBAN '.trim(chunk_split($realEstate->landlord_iban, 4, ' ')) : '' }}.
        @else
            Das Guthaben erstatten wir Ihnen in den nächsten Tagen auf Ihr Konto bzw. verrechnen es mit der nächsten Miete.
        @endif
    </p>
    <p class="small muted">Verteilung: nach Wohnfläche (Ihre Fläche / Gesamtfläche {{ number_format((float) ($statement->total_area ?: $realEstate->living_area), 2, ',', '.') }} m², zeitanteilig), nach Personen (Personentage) bzw. nach Wohneinheiten (zeitanteilig).
        Die Belege können Sie nach Absprache einsehen (§ 259 BGB). Einwendungen sind spätestens bis zum Ablauf des zwölften Monats nach Zugang dieser Abrechnung mitzuteilen (§ 556 Abs. 3 BGB).</p>
    @if ($statement->notes)
        <p style="white-space: pre-line;">{{ $statement->notes }}</p>
    @endif

    <p style="margin-top: 24pt;">Mit freundlichen Grüßen<br>{{ $realEstate->landlord_name }}</p>
</body>
</html>
