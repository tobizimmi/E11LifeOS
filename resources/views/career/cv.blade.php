<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-size: 10pt; color: #1d1d1f; margin: 16mm 16mm; line-height: 1.4; }
        h1 { font-size: 20pt; margin: 0; }
        .headline { font-size: 11pt; margin: 1mm 0 0; }
        .contact { color: #6e6e73; margin: 2mm 0 0; white-space: pre-line; font-size: 9pt; }
        h2 { font-size: 10.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: #6e6e73; border-bottom: 0.4pt solid #d2d2d7; padding-bottom: 1mm; margin: 7mm 0 2.5mm; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 0 0 3mm; }
        td.period { width: 32mm; color: #6e6e73; }
        .title { font-weight: bold; }
        .org { color: #3a3a3c; }
        .text { margin-top: 1mm; white-space: pre-line; color: #3a3a3c; }
        .summary { white-space: pre-line; }
    </style>
</head>
<body>
    <h1>{{ $user->name }}</h1>
    @if ($profile['headline'])<p class="headline accent">{{ $profile['headline'] }}</p>@endif
    @if ($profile['contact'])<p class="contact">{{ $profile['contact'] }}</p>@endif

    @if ($profile['summary'])
        <h2>{{ __('Profile') }}</h2>
        <p class="summary">{{ $profile['summary'] }}</p>
    @endif

    @foreach ($sections as $heading => $items)
        @if ($items->isNotEmpty())
            <h2>{{ $heading }}</h2>
            <table>
                @foreach ($items as $item)
                    <tr>
                        <td class="period">{{ $item->period() }}</td>
                        <td>
                            <span class="title">{{ $item->title }}</span>
                            @if ($item->organization || $item->location)<br><span class="org">{{ collect([$item->organization, $item->location])->filter()->implode(', ') }}</span>@endif
                            @if ($item->detail('grade'))<br><span class="org">{{ __('Grade') }}: {{ $item->detail('grade') }}</span>@endif
                            @if ($item->description && $item->type === 'job')<div class="text">{{ $item->description }}</div>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endforeach

    @if ($skills !== [])
        <h2>{{ __('Skills') }}</h2>
        <p>{{ implode(' · ', $skills) }}</p>
    @endif
    @if ($profile['languages'])
        <h2>{{ __('Languages') }}</h2>
        <p class="summary">{{ $profile['languages'] }}</p>
    @endif
</body>
</html>
