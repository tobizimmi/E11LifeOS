@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $title = match ($invoice->type) {
        'quote' => __('Sales quote'),
        'credit_note' => __('Credit note'),
        default => __('Invoice'),
    };
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $profile->accent_color) ? $profile->accent_color : '#4338ca';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $title }} {{ $invoice->number }} · {{ $profile->company_name }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <main class="max-w-2xl mx-auto px-4 py-8 space-y-5">
            <header class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    @if ($profile->logoDataUri())
                        <img src="{{ $profile->logoDataUri() }}" alt="{{ $profile->company_name }}" class="h-10 max-w-[8rem] rounded bg-white object-contain p-1">
                    @endif
                    <p class="text-lg font-semibold">{{ $profile->company_name }}</p>
                </div>
                <a href="{{ route('documents.public.pdf', $invoice->public_token) }}" class="rounded-lg px-3 py-2 text-sm font-medium text-white" style="background: {{ $accent }}">📄 {{ __('Download PDF') }}</a>
            </header>

            @if (session('status'))
                <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100" role="status">✓ {{ session('status') }}</p>
            @endif

            <section class="rounded-2xl bg-white p-6 shadow-sm dark:bg-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $title }} {{ $invoice->number }} · {{ $invoice->issued_on?->format('d.m.Y') }}</p>
                <h1 class="mt-1 text-2xl font-semibold">{{ $invoice->subject ?: $title }}</h1>
                <p class="mt-3 text-4xl font-semibold tabular-nums">{{ $euro($invoice->gross_total) }}</p>

                <p class="mt-2 text-sm">
                    @if ($invoice->type === 'invoice' && $invoice->status === 'paid')
                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">✓ {{ __('Paid – thank you!') }}</span>
                    @elseif ($invoice->type === 'invoice' && $invoice->status === 'open')
                        <span class="text-gray-600 dark:text-gray-300">{{ __('Open: :amount · due :date', ['amount' => $euro($invoice->outstanding()), 'date' => $invoice->due_on?->format('d.m.Y')]) }}</span>
                    @elseif ($invoice->isQuote() && $invoice->status === 'accepted')
                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">✓ {{ __('Accepted') }}</span>
                    @elseif ($invoice->isQuote() && $invoice->valid_until)
                        <span class="text-gray-600 dark:text-gray-300">{{ __('valid until :date', ['date' => $invoice->valid_until->format('d.m.Y')]) }}</span>
                    @elseif ($invoice->status === 'cancelled')
                        <span class="text-gray-600 dark:text-gray-300">{{ __('Cancelled') }}</span>
                    @endif
                </p>

                <ul class="mt-5 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($invoice->items as $item)
                        <li class="flex justify-between gap-4 py-2">
                            <span class="whitespace-pre-line">{{ $item->description }}</span>
                            <span class="shrink-0 tabular-nums">{{ $euro($item->lineTotal()) }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($invoice->type === 'invoice' && $invoice->status === 'open' && $profile->iban)
                    <div class="mt-5 rounded-xl bg-gray-50 p-4 text-sm dark:bg-gray-900/40">
                        <p class="font-medium">{{ __('Bank transfer') }}</p>
                        <p class="mt-1 text-gray-700 dark:text-gray-300">{{ $profile->company_name }}<br>IBAN: {{ trim(chunk_split($profile->iban, 4, ' ')) }}@if ($profile->bic)<br>BIC: {{ $profile->bic }}@endif<br>{{ __('Reference') }}: {{ $invoice->number }}</p>
                    </div>
                @endif

                @if ($invoice->isQuote() && $invoice->status === 'open' && ! $invoice->isExpired())
                    <form method="POST" action="{{ route('documents.public.accept', $invoice->public_token) }}" class="mt-5">
                        @csrf
                        <button type="submit" class="w-full rounded-lg px-4 py-3 text-sm font-semibold text-white sm:w-auto" style="background: {{ $accent }}" data-confirm="{{ __('Accept this quote with binding effect?') }}">✓ {{ __('Accept quote') }}</button>
                    </form>
                @endif
            </section>

            <footer class="text-center text-xs text-gray-500 dark:text-gray-400">{{ $profile->senderLine() }}@if ($profile->email) · {{ $profile->email }}@endif</footer>
        </main>
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.querySelectorAll('[data-confirm]').forEach((button) => button.addEventListener('click', (event) => { if (! confirm(button.dataset.confirm)) event.preventDefault(); }));
    </script>
</body>
</html>
