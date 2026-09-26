{{-- Logo, letterhead and address window ($profile, $logo, $recipient). --}}
    @if ($logo)
        <img src="{{ $logo }}" alt="" class="logo">
    @endif

    <div class="letterhead">
        <strong>{{ $profile->company_name }}</strong>
        @if ($profile->owner_name && $profile->owner_name !== $profile->company_name){{ $profile->owner_name }}<br>@endif
        {{ $profile->street }}<br>
        {{ trim($profile->postal_code.' '.$profile->city) }}<br>
        @if ($profile->phone){{ $profile->phone }}<br>@endif
        @if ($profile->email){{ $profile->email }}<br>@endif
        @if ($profile->website){{ $profile->website }}@endif
    </div>

    <div class="window">
        <div class="sender">{{ $profile->senderLine() }}</div>
        <div class="recipient">{{ $recipient }}</div>
    </div>

