@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $address = trim(implode(', ', array_filter([$realEstate->street, trim($realEstate->postal_code.' '.$realEstate->city)])));
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Mieterhöhung nach Indexmiete – {{ $tenant->name }}</title>
    <style>@include('real-estate.pdf.styles')</style>
</head>
<body>
    <p class="small muted">{{ $realEstate->landlord_name }}@if ($realEstate->landlord_address) · {{ $realEstate->landlord_address }}@endif</p>
    <p style="margin: 18pt 0;">{{ $tenant->name }}<br>{{ $tenant->unit ? $tenant->unit.', ' : '' }}{{ $address ?: $realEstate->name }}</p>
    <p style="text-align: right;">{{ today()->format('d.m.Y') }}</p>

    <h1>Anpassung der Miete nach § 557b BGB (Indexmiete)</h1>
    <p>Sehr geehrte Damen und Herren,</p>
    <p>in Ihrem Mietvertrag über die Wohnung {{ $tenant->unit ? $tenant->unit.', ' : '' }}{{ $address ?: $realEstate->name }} ist eine Indexmiete vereinbart. Die Nettokaltmiete verändert sich danach im gleichen Verhältnis wie der vom Statistischen Bundesamt ermittelte Verbraucherpreisindex für Deutschland (Basis 2020 = 100).</p>

    <table class="grid" style="margin: 8pt 0;">
        <tr><td>Index bei Vertragsbeginn bzw. letzter Anpassung{{ $tenant->index_base_month ? ' ('.\Illuminate\Support\Carbon::createFromFormat('Y-m', $tenant->index_base_month)->translatedFormat('F Y').')' : '' }}</td><td class="num">{{ number_format($tenant->index_base, 1, ',', '.') }}</td></tr>
        <tr><td>Aktueller Index{{ $currentMonth ? ' ('.\Illuminate\Support\Carbon::createFromFormat('Y-m', $currentMonth)->translatedFormat('F Y').')' : '' }}</td><td class="num">{{ number_format($currentIndex, 1, ',', '.') }}</td></tr>
        <tr><td>Veränderung</td><td class="num">{{ number_format($calculation['percent'], 2, ',', '.') }} %</td></tr>
        <tr><td>Bisherige Nettokaltmiete</td><td class="num">{{ $euro($calculation['old']) }}</td></tr>
        <tr class="total"><td>Neue Nettokaltmiete ({{ $euro($calculation['old']) }} × {{ number_format($currentIndex, 1, ',', '.') }} / {{ number_format($tenant->index_base, 1, ',', '.') }})</td><td class="num">{{ $euro($calculation['new']) }}</td></tr>
    </table>

    <p>Die Nettokaltmiete erhöht sich damit um {{ $euro($calculation['increase']) }} auf {{ $euro($calculation['new']) }} monatlich. Die neue Miete ist ab dem <strong>{{ $calculation['effective_on']->format('d.m.Y') }}</strong> zu zahlen (Beginn des übernächsten Monats nach Zugang dieser Erklärung, § 557b Abs. 3 BGB). Die Vorauszahlung für die Betriebskosten von {{ $euro($tenant->utilities_advance) }} bleibt unverändert; ab dann beträgt Ihre monatliche Gesamtmiete {{ $euro($calculation['new'] + $tenant->utilities_advance) }}.</p>
    @if ($realEstate->landlord_iban)
        <p>Bitte passen Sie Ihren Dauerauftrag an (IBAN {{ $realEstate->landlord_iban }}).</p>
    @endif
    <p>Mit freundlichen Grüßen</p>

    <table class="signature"><tr><td><div class="line">{{ $realEstate->landlord_name ?: 'Vermieter' }}</div></td><td></td></tr></table>
    <p class="small muted" style="margin-top: 18pt;">Hinweis: Diese Vorlage bitte vor dem Versand prüfen. Die Indexwerte finden Sie bei Destatis (Verbraucherpreisindex, Tabelle 61111-0002).</p>
</body>
</html>
