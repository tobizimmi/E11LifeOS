{{-- Footer with business, tax and bank details ($profile). --}}
    <div class="footer">
        <table style="width: 100%">
            <tr>
                <td>{{ $profile->company_name }}@if ($profile->owner_name && $profile->owner_name !== $profile->company_name)<br>{{ __('Owner') }}: {{ $profile->owner_name }}@endif<br>{{ $profile->street }}<br>{{ trim($profile->postal_code.' '.$profile->city) }}</td>
                <td>@if ($profile->tax_number){{ __('Tax number') }}: {{ $profile->tax_number }}<br>@endif @if ($profile->vat_id){{ __('VAT ID') }}: {{ $profile->vat_id }}<br>@endif @if ($profile->email){{ $profile->email }}@endif</td>
                <td>@if ($profile->iban){{ $profile->bank_name }}<br>IBAN: {{ trim(chunk_split($profile->iban, 4, ' ')) }}<br>@if ($profile->bic)BIC: {{ $profile->bic }}@endif @endif</td>
            </tr>
        </table>
    </div>
