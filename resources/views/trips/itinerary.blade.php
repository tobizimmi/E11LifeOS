{{-- Travel plan to take along: an offline page (kept by the service worker) or a PDF, in the design of the PDF editor. --}}
@php
    $accent = $theme['accent'];
    $bookings = $trip->bookings->sortBy(fn ($booking) => $booking->starts_at?->timestamp ?? PHP_INT_MAX);
    $packing = $trip->packingItems->groupBy('category');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    @if ($offline)
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
    @endif
    <title>{{ $trip->title }}</title>
    <style>
        @page { margin: 16mm 16mm 18mm; }
        body { font-size: 10pt; color: #1d1d1f; line-height: 1.45; margin: 0; }
        @if ($offline)
            body { font-size: 15px; background: #f5f5f7; }
            .sheet { max-width: 720px; margin: 0 auto; padding: 16px; }
            .card { background: #fff; border-radius: 14px; padding: 14px 16px; margin-bottom: 12px; }
            .offline-note { font-size: 12px; color: #86868b; margin: 8px 0 12px; }
            .offline-note a { color: {{ $accent }}; }
        @else
            .card { margin-bottom: 14pt; }
        @endif
        .eyebrow { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.2pt; color: {{ $accent }}; margin: 0 0 3pt; }
        h1 { font-size: {{ $offline ? '28px' : '24pt' }}; line-height: 1.1; letter-spacing: -0.4pt; margin: 0; }
        .lead { color: #6e6e73; margin: 4pt 0 0; }
        h2 { font-size: 8pt; text-transform: uppercase; letter-spacing: 1.2pt; color: #86868b; margin: 0 0 6pt; }
        h3 { font-size: {{ $offline ? '16px' : '11pt' }}; margin: 0 0 4pt; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 4pt 0; border-bottom: 0.5pt solid #e5e5ea; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        .time { width: {{ $offline ? '70px' : '48pt' }}; color: #6e6e73; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .icon { width: {{ $offline ? '28px' : '18pt' }}; }
        .muted { color: #86868b; font-size: {{ $offline ? '13px' : '8.5pt' }}; }
        .code { font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; letter-spacing: 0.5pt; }
        .day { page-break-inside: avoid; }
        .day-number { color: {{ $accent }}; font-weight: bold; }
        .packing td { width: 50%; border: none; padding: 1pt 8pt 1pt 0; }
    </style>
</head>
<body>
<div class="sheet">
    @if ($offline)
        <p class="offline-note">📴 {{ __('Saved for offline use') }} · {{ __('As of :date', ['date' => now()->format('d.m.Y H:i')]) }} · <a href="{{ route('trips.show', $trip) }}">{{ __('Back to the trip') }}</a></p>
    @endif

    <div class="card">
        <p class="eyebrow">{{ __('Travel plan') }}</p>
        <h1>{{ $trip->title }}</h1>
        <p class="lead">
            @if ($trip->destination){{ $trip->destination }} · @endif{{ $trip->starts_on->translatedFormat('j. M') }} – {{ $trip->ends_on->translatedFormat('j. M Y') }} · {{ trans_choice(':count night|:count nights', $trip->nights()) }}
        </p>
        @if ($trip->travellerNames() !== [])
            <p class="muted">{{ implode(', ', $trip->travellerNames()) }}</p>
        @endif
    </div>

    @if ($bookings->isNotEmpty())
        <div class="card">
            <h2>{{ __('Bookings') }}</h2>
            <table>
                @foreach ($bookings as $booking)
                    <tr>
                        <td class="icon">{{ $kinds[$booking->kind]['icon'] ?? '📌' }}</td>
                        <td>
                            <strong>{{ $booking->title }}</strong>
                            <div class="muted">{{ collect([$booking->starts_at?->translatedFormat('D, j. M H:i'), $booking->ends_at ? '→ '.$booking->ends_at->translatedFormat('D, j. M H:i') : null, $booking->provider])->filter()->implode(' · ') }}</div>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">@if ($booking->reference)<span class="code">{{ $booking->reference }}</span>@endif</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @foreach ($itinerary['days'] as $day)
        @continue($day['stops']->isEmpty() && $day['bookings'] === [])
        <div class="card day">
            <h3><span class="day-number">{{ __('Day :number', ['number' => $day['number']]) }}</span> · {{ $day['date']->translatedFormat('l, j. F') }}</h3>
            <table>
                @foreach ($day['bookings'] as $entry)
                    <tr>
                        <td class="time">{{ $entry['time'] }}</td>
                        <td class="icon">{{ $kinds[$entry['booking']->kind]['icon'] ?? '📌' }}</td>
                        <td>{{ $entry['label'] }}@if ($entry['booking']->reference) <span class="muted">· {{ $entry['booking']->reference }}</span>@endif</td>
                    </tr>
                @endforeach
                @foreach ($day['stops'] as $stop)
                    <tr>
                        <td class="time">{{ $stop->time ? substr($stop->time, 0, 5) : '' }}@if ($stop->end_time)–{{ substr($stop->end_time, 0, 5) }}@endif</td>
                        <td class="icon">{{ $stopKinds[$stop->kind]['icon'] ?? '📍' }}</td>
                        <td>
                            {{ $stop->title }}
                            @if ($stop->location)<div class="muted">{{ $stop->location }}</div>@endif
                            @if ($stop->notes)<div class="muted">{{ $stop->notes }}</div>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach

    @if ($packing->isNotEmpty())
        <div class="card">
            <h2>{{ __('Packing list') }}</h2>
            @foreach ($packing as $category => $items)
                <p class="muted" style="margin: 6pt 0 2pt;">{{ $categories[$category]['icon'] ?? '' }} {{ $categories[$category]['label'] ?? $category }}</p>
                <table class="packing">
                    @foreach ($items->chunk(2) as $pair)
                        <tr>
                            @foreach ($pair as $item)
                                <td>{{ $item->packed ? '☑' : '☐' }} {{ $item->label }}@if ($item->person) <span class="muted">({{ $item->person }})</span>@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            @endforeach
        </div>
    @endif

    @if ($trip->notes)
        <div class="card">
            <h2>{{ __('Notes') }}</h2>
            <p style="margin: 0; white-space: pre-line;">{{ $trip->notes }}</p>
        </div>
    @endif
</div>
</body>
</html>
