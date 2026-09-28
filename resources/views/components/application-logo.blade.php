@php
    // Unique per use: a gradient defined inside a hidden copy (mobile bar) would not render elsewhere.
    $gradientId = 'e11-logo-'.\Illuminate\Support\Str::random(6);
@endphp
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="{{ config('app.name') }}" {{ $attributes }}>
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#6366f1" />
            <stop offset="1" stop-color="#4338ca" />
        </linearGradient>
    </defs>
    <rect width="64" height="64" rx="16" fill="url(#{{ $gradientId }})" />
    <rect x="16" y="17" width="32" height="6" rx="3" fill="#ffffff" />
    <rect x="16" y="29" width="22" height="6" rx="3" fill="#ffffff" fill-opacity="0.85" />
    <rect x="16" y="41" width="27" height="6" rx="3" fill="#ffffff" fill-opacity="0.7" />
</svg>
