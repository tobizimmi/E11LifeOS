<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ __('Emergency folder') }} – {{ $owner->name }}</title>
    <style>
        @page { margin: 18mm 18mm 18mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111827; line-height: 1.45; }
        h1 { font-size: 16pt; margin: 0 0 2mm; }
        h2 { font-size: 11.5pt; margin: 7mm 0 2mm; padding-bottom: 1mm; border-bottom: 0.6pt solid #9ca3af; }
        .muted { color: #6b7280; }
        .entry { margin: 0 0 2.5mm; page-break-inside: avoid; }
        .title { font-weight: bold; }
        .details { white-space: pre-line; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1mm 0; vertical-align: top; border-bottom: 0.3pt solid #e5e7eb; }
        td.right { text-align: right; color: #4b5563; }
    </style>
</head>
<body>
    <h1>{{ __('Emergency folder') }} – {{ $owner->name }}</h1>
    <p class="muted">{{ __('As of :date', ['date' => today()->format('d.m.Y')]) }} · {{ __('Confidential – keep in a safe place.') }}</p>

    @foreach ($sections as $key => $info)
        <h2>{{ $info['label'] }}</h2>
        @foreach ($info['presets'] as $kind => $preset)
            @php
                $matching = $entries->where('section', $key)->where('kind', $kind);
            @endphp
            @forelse ($matching as $item)
                <div class="entry">
                    <div class="title">☑ {{ $item->title }}</div>
                    @if ($item->details)<div class="details">{{ $item->details }}</div>@endif
                    @if ($item->location)<div>{{ __('Original') }}: {{ $item->location }}</div>@endif
                    @if ($item->expires_on)<div class="muted">{{ __('valid until :date', ['date' => $item->expires_on->format('d.m.Y')]) }}</div>@endif
                </div>
            @empty
                <div class="entry muted">☐ {{ $preset['label'] }} – {{ __('not recorded yet') }}</div>
            @endforelse
        @endforeach
        @foreach ($entries->where('section', $key)->where('kind', \App\Models\EmergencyEntry::CUSTOM) as $item)
            <div class="entry">
                <div class="title">☑ {{ $item->title }}</div>
                @if ($item->details)<div class="details">{{ $item->details }}</div>@endif
                @if ($item->location)<div>{{ __('Original') }}: {{ $item->location }}</div>@endif
            </div>
        @endforeach
    @endforeach

    @foreach ($overview as $group)
        <h2>{{ $group['label'] }}</h2>
        <table>
            @foreach ($group['rows'] as $row)
                <tr><td>{{ $row['label'] }}</td><td class="right">{{ $row['detail'] }}</td></tr>
            @endforeach
        </table>
    @endforeach
</body>
</html>
