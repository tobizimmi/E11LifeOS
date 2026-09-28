@php
    $euro = fn ($value) => $value !== null ? number_format((float) $value, 2, ',', '.').' €' : '';
    $address = trim(implode(', ', array_filter([$realEstate->street, trim($realEstate->postal_code.' '.$realEstate->city)])));
    $yesNo = fn (?bool $value) => $applicant ? '<span class="check">'.($value ? '✓' : '').'</span> ja &nbsp; <span class="check">'.($value ? '' : '✓').'</span> nein' : '<span class="check"></span> ja &nbsp; <span class="check"></span> nein';
    $fields = [
        ['Name, Vorname', $applicant?->name],
        ['Geburtsdatum', $applicant?->birth_date?->format('d.m.Y')],
        ['Derzeitige Anschrift', $applicant?->current_address],
        ['Telefon', $applicant?->phone],
        ['E-Mail', $applicant?->email],
        ['Beruf', $applicant?->occupation],
        ['Arbeitgeber', $applicant?->employer],
        ['Beschäftigt seit', $applicant?->employed_since?->format('m/Y')],
        ['Monatliches Nettoeinkommen (Haushalt)', $euro($applicant?->net_income)],
        ['Einziehende Personen insgesamt / davon Kinder', $applicant ? $applicant->persons.' / '.$applicant->children : null],
        ['Haustiere (Art, Anzahl)', $applicant ? ($applicant->pets ?: 'keine') : null],
        ['Derzeitiger Vermieter (Name, Telefon)', $applicant?->current_landlord],
        ['Gewünschter Einzug', $applicant?->desired_move_in?->format('d.m.Y')],
    ];
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Mieterselbstauskunft</title>
    <style>@include('real-estate.pdf.styles')</style>
</head>
<body>
    <h1>Mieterselbstauskunft</h1>
    <p>für das Mietobjekt {{ $address ?: $realEstate->name }}@if ($realEstate->landlord_name) · Vermieter: {{ $realEstate->landlord_name }}@endif</p>
    @if ($applicant)
        <p class="small muted">Online ausgefüllt am {{ $applicant->created_at->format('d.m.Y, H:i') }} Uhr · Einwilligung zur Datenverarbeitung erteilt am {{ $applicant->consent_at?->format('d.m.Y, H:i') }} Uhr</p>
    @endif

    <table style="margin-top: 8pt;">
        @foreach ($fields as [$label, $value])
            <tr>
                <td style="padding: 4pt 0;">
                    <div class="label">{{ $label }}</div>
                    <div class="field">{{ $value }}</div>
                </td>
            </tr>
        @endforeach
    </table>

    <h2>Angaben zur Zuverlässigkeit</h2>
    <table class="grid">
        <tr><td>Raucher</td><td class="num">{!! $yesNo($applicant?->smoker) !!}</td></tr>
        <tr><td>Bestehen Mietrückstände aus bisherigen Mietverhältnissen?</td><td class="num">{!! $yesNo($applicant?->rent_arrears) !!}</td></tr>
        <tr><td>Wurde in den letzten 5 Jahren ein Insolvenzverfahren eröffnet oder eine Vermögensauskunft abgegeben?</td><td class="num">{!! $yesNo($applicant?->insolvency) !!}</td></tr>
        <tr><td>Wurde in den letzten 5 Jahren eine Räumungsklage gegen Sie erhoben?</td><td class="num">{!! $yesNo($applicant?->eviction) !!}</td></tr>
        <tr><td>Kann eine aktuelle Bonitätsauskunft (z. B. SCHUFA) vorgelegt werden?</td><td class="num">{!! $yesNo($applicant?->credit_report) !!}</td></tr>
    </table>

    @if ($applicant?->message)
        <h2>Nachricht</h2>
        <p style="white-space: pre-line;">{{ $applicant->message }}</p>
    @endif

    <p class="small" style="margin-top: 12pt;">Ich versichere, dass die Angaben vollständig und wahr sind. Mir ist bekannt, dass falsche Angaben den Vermieter zur Anfechtung oder fristlosen Kündigung
        des Mietvertrags berechtigen können. Die Angaben sind freiwillig und werden nur zur Auswahl der Mieter verarbeitet (Art. 6 Abs. 1 lit. b DSGVO). Kommt kein Mietvertrag zustande,
        werden sie spätestens nach sechs Monaten gelöscht.</p>

    <table class="signature">
        <tr>
            <td><div class="line">Ort, Datum</div></td>
            <td><div class="line">Unterschrift Mietinteressent(en)</div></td>
        </tr>
    </table>
</body>
</html>
