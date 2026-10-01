{{-- Payments to choose from: regular debits without a contract first, then every other payee of the bank bookings. --}}
@if ($unmatchedPayments->isNotEmpty())
    <optgroup label="{{ __('Regular payments without a contract') }}">
        @foreach ($unmatchedPayments as $payment)
            <option value="{{ $payment['key'] }}">{{ $payment['name'] }} · {{ number_format($payment['monthly'], 2, ',', '.') }} € / {{ __('month') }}</option>
        @endforeach
    </optgroup>
@endif
@if ($otherPayments->isNotEmpty())
    <optgroup label="{{ __('All other payments') }}">
        @foreach ($otherPayments as $payment)
            <option value="{{ $payment['key'] }}">{{ $payment['name'] }} · {{ number_format($payment['amount'], 2, ',', '.') }} € · {{ $payment['lastDate']->format('d.m.Y') }}{{ $payment['count'] > 1 ? ' · '.$payment['count'].'×' : '' }}</option>
        @endforeach
    </optgroup>
@endif
