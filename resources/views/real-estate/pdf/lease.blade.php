@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $address = trim(implode(', ', array_filter([$realEstate->street, trim($realEstate->postal_code.' '.$realEstate->city)])));
    $landlord = $realEstate->landlord_name ?: '______________________________';
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Mietvertrag {{ $tenant->name }}</title>
    <style>@include('real-estate.pdf.styles')</style>
</head>
<body>
    <p class="notice">Entwurf aus {{ config('app.name') }} – bitte vor der Unterschrift prüfen und bei Bedarf anpassen. Keine Rechtsberatung.</p>

    <h1>Mietvertrag über Wohnraum</h1>

    <p>Zwischen</p>
    <div class="box">
        <strong>{{ $landlord }}</strong>@if ($realEstate->landlord_address), {{ $realEstate->landlord_address }}@endif<br>
        <span class="muted small">– Vermieter –</span>
    </div>
    <p>und</p>
    <div class="box">
        <strong>{{ $tenant->name }}</strong>@if ($tenant->previous_address), bisher wohnhaft {{ $tenant->previous_address }}@endif<br>
        <span class="muted small">– Mieter –</span>
    </div>
    <p>wird folgender Mietvertrag geschlossen:</p>

    <h2>§ 1 Mietsache</h2>
    <p>Vermietet wird die Wohnung {{ $tenant->unit ? '„'.$tenant->unit.'“ ' : '' }}im Objekt {{ $address ?: $realEstate->name }}
        @if ($tenant->area) mit einer Wohnfläche von ca. {{ number_format($tenant->area, 2, ',', '.') }} m²@endif.
        Die Wohnfläche dient nicht zur Festlegung des Mietgegenstands. Die Wohnung wird zu Wohnzwecken für {{ $tenant->persons }} {{ $tenant->persons === 1 ? 'Person' : 'Personen' }} vermietet.</p>

    <h2>§ 2 Mietzeit</h2>
    @if ($tenant->lease_end_on)
        <p>Das Mietverhältnis beginnt am {{ $tenant->move_in_on->format('d.m.Y') }} und endet am {{ $tenant->lease_end_on->format('d.m.Y') }}.
            Die Befristung erfolgt aus folgendem Grund (§ 575 BGB): ____________________________________________.</p>
    @else
        <p>Das Mietverhältnis beginnt am {{ $tenant->move_in_on->format('d.m.Y') }} und läuft auf unbestimmte Zeit.</p>
    @endif

    <h2>§ 3 Miete und Nebenkosten</h2>
    <table class="grid" style="width: 70%;">
        <tr><td>Nettokaltmiete monatlich</td><td class="num">{{ $euro($tenant->cold_rent) }}</td></tr>
        <tr><td>Vorauszahlung auf die Betriebskosten (inkl. Heizung/Warmwasser)</td><td class="num">{{ $euro($tenant->utilities_advance) }}</td></tr>
        <tr class="total"><td>Gesamtmiete monatlich</td><td class="num">{{ $euro($tenant->monthlyTotal()) }}</td></tr>
    </table>
    <p style="margin-top: 6pt;">Die Miete ist monatlich im Voraus, spätestens bis zum {{ $tenant->due_day }}. Werktag des Monats, kostenfrei
        @if ($realEstate->landlord_iban) auf das Konto IBAN {{ trim(chunk_split($realEstate->landlord_iban, 4, ' ')) }} @else auf ein vom Vermieter benanntes Konto @endif
        zu zahlen. Maßgeblich ist der Eingang des Geldes.</p>

    <h2>§ 4 Mietanpassung</h2>
    @if ($tenant->rent_adjustment === 'index')
        <p>Die Nettokaltmiete wird durch den vom Statistischen Bundesamt ermittelten Verbraucherpreisindex für Deutschland bestimmt (Indexmiete, § 557b BGB).
            Ändert sich der Index, kann jede Partei durch Erklärung in Textform eine entsprechende Anpassung verlangen, frühestens ein Jahr nach der letzten Änderung.</p>
    @elseif ($tenant->rent_adjustment === 'graduated')
        <p>Die Nettokaltmiete erhöht sich jeweils nach Ablauf von mindestens einem Jahr wie folgt (Staffelmiete, § 557a BGB):</p>
        <p>ab __________ auf __________ € · ab __________ auf __________ € · ab __________ auf __________ €</p>
    @else
        <p>Für Mieterhöhungen gelten die gesetzlichen Vorschriften (§§ 558 ff. BGB).</p>
    @endif

    <h2>§ 5 Betriebskosten</h2>
    <p>Der Mieter trägt die Betriebskosten im Sinne des § 2 der Betriebskostenverordnung (BetrKV), insbesondere Grundsteuer, Wasser und Abwasser, Heizung und Warmwasser,
        Straßenreinigung und Müllbeseitigung, Gebäudereinigung, Gartenpflege, Beleuchtung, Schornsteinreinigung, Sach- und Haftpflichtversicherung, Hauswart sowie Kabel/Antenne.
        Über die Vorauszahlungen wird jährlich abgerechnet (§ 556 Abs. 3 BGB). Umlageschlüssel ist die Wohnfläche, soweit nicht verbrauchs- oder personenabhängig abgerechnet wird;
        Heiz- und Warmwasserkosten werden nach der Heizkostenverordnung verteilt.</p>

    <h2>§ 6 Mietsicherheit</h2>
    @if ($tenant->deposit > 0)
        <p>Der Mieter leistet eine Kaution von {{ $euro($tenant->deposit) }} (höchstens drei Nettokaltmieten, § 551 BGB). Er ist berechtigt, sie in drei gleichen monatlichen Teilzahlungen zu erbringen,
            die erste zu Beginn des Mietverhältnisses. Der Vermieter legt die Kaution getrennt von seinem Vermögen bei einem Kreditinstitut zum üblichen Zinssatz an.</p>
    @else
        <p>Eine Mietsicherheit wird nicht vereinbart.</p>
    @endif

    <h2>§ 7 Kündigung</h2>
    <p>Es gelten die gesetzlichen Kündigungsfristen (§ 573c BGB). Der Mieter kann spätestens am dritten Werktag eines Kalendermonats zum Ablauf des übernächsten Monats kündigen
        @if ($tenant->notice_months !== 3) (vereinbarte Frist für den Mieter: {{ $tenant->notice_months }} {{ $tenant->notice_months === 1 ? 'Monat' : 'Monate' }}; eine längere Frist als die gesetzliche ist unwirksam)@endif.
        Die Kündigung bedarf der Schriftform (§ 568 BGB).</p>

    <h2>§ 8 Nutzung, Tierhaltung, Untervermietung</h2>
    <p>Die Haltung von Kleintieren ist erlaubt. Für andere Tiere und für eine Untervermietung ist die vorherige Zustimmung des Vermieters erforderlich, die nur aus wichtigem Grund versagt werden darf.</p>

    <h2>§ 9 Übergabe</h2>
    <p>Über den Zustand der Wohnung und die übergebenen Schlüssel wird bei der Übergabe ein Protokoll erstellt, das Bestandteil dieses Vertrags ist.</p>

    <h2>§ 10 Besondere Vereinbarungen</h2>
    <p style="white-space: pre-line;">{{ $tenant->special_terms ?: 'Keine.' }}</p>

    <h2>§ 11 Schlussbestimmungen</h2>
    <p>Änderungen und Ergänzungen dieses Vertrags bedürfen der Textform. Sollte eine Bestimmung unwirksam sein, bleibt die Wirksamkeit der übrigen Bestimmungen unberührt.</p>

    <table class="signature">
        <tr>
            <td><div class="line">Ort, Datum · Vermieter ({{ $landlord }})</div></td>
            <td><div class="line">Ort, Datum · Mieter ({{ $tenant->name }})</div></td>
        </tr>
    </table>
</body>
</html>
