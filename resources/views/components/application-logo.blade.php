@php
    // Unique per use: a gradient defined inside a hidden copy (mobile bar) would not render elsewhere.
    $gradientId = 'e11-logo-'.\Illuminate\Support\Str::random(6);
@endphp
{{-- The app icon: a house (the household) holding connected thoughts (the second brain). --}}
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="{{ config('app.name') }}" {{ $attributes }}>
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#6366f1" />
            <stop offset="0.55" stop-color="#4f46e5" />
            <stop offset="1" stop-color="#7c3aed" />
        </linearGradient>
    </defs>
    <rect width="64" height="64" rx="15" fill="url(#{{ $gradientId }})" />
    <path d="M32 14 L50 28.5 Q51 29.4 51 30.8 L51 47 Q51 50 48 50 L16 50 Q13 50 13 47 L13 30.8 Q13 29.4 14 28.5 Z" fill="#ffffff" fill-opacity="0.14" stroke="#ffffff" stroke-width="3.6" stroke-linejoin="round" />
    <g stroke="#ffffff" stroke-width="2" stroke-linecap="round">
        <line x1="24.5" y1="41" x2="32" y2="32.5" />
        <line x1="32" y1="32.5" x2="39.5" y2="40" />
        <line x1="24.5" y1="41" x2="39.5" y2="40" />
    </g>
    <circle cx="24.5" cy="41" r="3.4" fill="#ffffff" />
    <circle cx="39.5" cy="40" r="3.4" fill="#ffffff" />
    <circle cx="32" cy="32.5" r="4.2" fill="#fbbf24" stroke="#ffffff" stroke-width="1.4" />
</svg>
