{{-- Vaccination record of one person (PDF), in the design of the PDF editor. --}}
@php($accent = $theme['accent'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Vaccination record') }} – {{ $profile->name }}</title>
    <style>
        @page { margin: 16mm 16mm 20mm; }
        body { font-size: 10pt; color: #1d1d1f; line-height: 1.45; }
        .eyebrow { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.2pt; color: {{ $accent }}; margin: 0 0 3pt; }
        h1 { font-size: 24pt; letter-spacing: -0.4pt; margin: 0; }
        .lead { color: #6e6e73; margin: 4pt 0 16pt; }
        h2 { font-size: 8pt; text-transform: uppercase; letter-spacing: 1.2pt; color: #86868b; margin: 18pt 0 6pt; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #86868b; padding: 0 8pt 4pt 0; border-bottom: 0.6pt solid #d2d2d7; }
        td { padding: 5pt 8pt 5pt 0; border-bottom: 0.5pt solid #e5e5ea; vertical-align: top; }
        .due { color: #c2410c; font-weight: bold; }
        .muted { color: #86868b; font-size: 8.5pt; }
        .note { margin-top: 18pt; font-size: 8pt; color: #86868b; }
    </style>
</head>
<body>
    <p class="eyebrow">{{ __('Vaccination record') }}</p>
    <h1>{{ $profile->name }}</h1>
    <p class="lead">@if ($profile->birth_date){{ __('born :date', ['date' => $profile->birth_date->format('d.m.Y')]) }} · @endif{{ __('As of :date', ['date' => now()->format('d.m.Y')]) }}</p>

    <h2>💉 {{ __('Vaccinations') }}</h2>
    @if ($vaccinations->isEmpty())
        <p class="muted">{{ __('No vaccinations entered yet.') }}</p>
    @else
        <table>
            <tr><th>{{ __('Vaccination') }}</th><th>{{ __('Last given') }}</th><th>{{ __('Due again') }}</th><th>{{ __('Notes') }}</th></tr>
            @foreach ($vaccinations as $entry)
                <tr>
                    <td><strong>{{ $entry->title }}</strong>@if ($entry->dosage)<div class="muted">{{ $entry->dosage }}</div>@endif</td>
                    <td>{{ $entry->done_on?->format('d.m.Y') ?? '–' }}</td>
                    <td @class(['due' => $entry->next_due_on && $entry->next_due_on->lte(today()->addMonths(3))])>{{ $entry->next_due_on?->format('d.m.Y') ?? '–' }}</td>
                    <td class="muted">{{ $entry->notes }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($checkups->isNotEmpty())
        <h2>🩺 {{ __('Check-ups') }}</h2>
        <table>
            <tr><th>{{ __('Check-up') }}</th><th>{{ __('Last') }}</th><th>{{ __('Next') }}</th></tr>
            @foreach ($checkups as $entry)
                <tr>
                    <td>{{ $entry->title }}</td>
                    <td>{{ $entry->done_on?->format('d.m.Y') ?? '–' }}</td>
                    <td @class(['due' => $entry->next_due_on && $entry->next_due_on->lte(today()->addMonths(3))])>{{ $entry->next_due_on?->format('d.m.Y') ?? '–' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="note">{{ __('Overview from E11LifeOS – not a replacement for the official vaccination certificate.') }}</p>
</body>
</html>
