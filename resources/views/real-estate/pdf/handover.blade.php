@php
    $address = trim(implode(', ', array_filter([$realEstate->street, trim($realEstate->postal_code.' '.$realEstate->city)])));
    $kind = $handover->kind === 'move_in' ? 'Einzug' : 'Auszug';
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Übergabeprotokoll {{ $kind }}</title>
    <style>@include('real-estate.pdf.styles')</style>
</head>
<body>
    <h1>Wohnungsübergabeprotokoll – {{ $kind }}</h1>
    <p>{{ $address ?: $realEstate->name }}@if ($handover->tenant?->unit), {{ $handover->tenant->unit }}@endif · Übergabe am {{ $handover->handed_over_on->format('d.m.Y') }}</p>
    <p>Vermieter: {{ $realEstate->landlord_name ?: '–' }} · Mieter: {{ $handover->tenant?->name ?? '–' }}</p>

    <h2>Zählerstände</h2>
    <table class="grid">
        <thead><tr><th>Zähler</th><th>Zählernummer</th><th class="num">Stand</th></tr></thead>
        <tbody>
            @forelse ($handover->meters ?? [] as $meter)
                <tr><td>{{ $meter['label'] ?? '' }}</td><td>{{ $meter['number'] ?? '' }}</td><td class="num">{{ $meter['reading'] ?? '' }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">–</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Schlüssel</h2>
    <table class="grid">
        <thead><tr><th>Schlüssel</th><th class="num">Anzahl</th></tr></thead>
        <tbody>
            @forelse ($handover->keys ?? [] as $key)
                <tr><td>{{ $key['label'] ?? '' }}</td><td class="num">{{ $key['count'] ?? '' }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">–</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Zustand der Räume</h2>
    <table class="grid">
        <thead><tr><th>Raum</th><th>Zustand</th><th>Bemerkungen / Mängel</th></tr></thead>
        <tbody>
            @forelse ($handover->rooms ?? [] as $room)
                <tr><td>{{ $room['label'] ?? '' }}</td><td>{{ ($room['ok'] ?? true) ? 'in Ordnung' : 'Mängel' }}</td><td>{{ $room['notes'] ?? '' }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">–</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($handover->notes)
        <h2>Weitere Vereinbarungen</h2>
        <p>{!! nl2br(e($handover->notes)) !!}</p>
    @endif

    <table class="signature"><tr><td><div class="line">Vermieter</div></td><td><div class="line">Mieter</div></td></tr></table>

    @if ($photos !== [])
        <h2 style="page-break-before: always;">Fotos</h2>
        @foreach ($photos as $photo)
            <p><img src="{{ $photo }}" style="max-width: 100%; max-height: 110mm;"></p>
        @endforeach
    @endif
</body>
</html>
