@php
    $senderLines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $letter['sender']))));
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $letter['subject'] ?: __('Letter') }}</title>
    <style>
        @page { margin: 20mm 20mm 20mm 25mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #111827; line-height: 1.5; }
        .sender { font-size: 7.5pt; color: #4b5563; border-bottom: 0.5pt solid #9ca3af; padding-bottom: 1pt; margin-top: 17mm; width: 85mm; }
        .recipient { height: 40mm; width: 85mm; padding-top: 4mm; white-space: pre-line; }
        .head { text-align: right; white-space: pre-line; margin-bottom: 8mm; }
        .subject { font-weight: bold; margin-bottom: 8mm; }
        .body { white-space: pre-line; }
        .signature { margin-top: 18mm; }
    </style>
</head>
<body>
    <div class="head">{{ implode("\n", $senderLines) }}</div>
    <div class="sender">{{ implode(' · ', $senderLines) }}</div>
    <div class="recipient">{{ trim($letter['recipient']) }}</div>
    <p style="text-align: right;">{{ trim($letter['place']) !== '' ? trim($letter['place']).', ' : '' }}{{ today()->format('d.m.Y') }}</p>
    <p class="subject">{{ $letter['subject'] }}</p>
    <div class="body">{{ trim($letter['body']) }}</div>
    <p class="signature">{{ __('Kind regards') }}</p>
    <p style="margin-top: 14mm;">{{ $senderLines[0] ?? $user->name }}</p>
</body>
</html>
